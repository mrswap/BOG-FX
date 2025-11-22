<?php

namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexMatch;
use App\Models\Currency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ForexFifoService
{
    /**
     * Apply FIFO matching and compute realised/unrealised.
     */
    public function applyFifoFor(int $partyId, string $ledgerType, int $baseCurrencyId, $closingRateGlobal = null)
    {
        Log::info("===== FIFO START =====");
        Log::info("Party: {$partyId} | LedgerType: {$ledgerType} | Currency: {$baseCurrencyId}");

        DB::transaction(function () use ($partyId, $ledgerType, $baseCurrencyId, $closingRateGlobal) {

            // Load ordered transactions
            $txns = ForexRemittance::where('party_id', $partyId)
                ->where('ledger_type', $ledgerType)
                ->where('base_currency_id', $baseCurrencyId)
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // debit = sale/payment, credit = receipt/purchase
            $debits = $txns->filter(fn($t) => $t->direction === 'debit' && $t->remaining_base_amount > 0)->values();
            $credits = $txns->filter(fn($t) => $t->direction === 'credit' && $t->remaining_base_amount > 0)->values();

            $di = 0;
            $ci = 0;

            while ($di < $debits->count() && $ci < $credits->count()) {

                $debit = $debits[$di];
                $credit = $credits[$ci];

                $matchAmount = min($debit->remaining_base_amount, $credit->remaining_base_amount);
                if ($matchAmount <= 0) {
                    if ($debit->remaining_base_amount <= 0) $di++;
                    if ($credit->remaining_base_amount <= 0) $ci++;
                    continue;
                }

                $debitRate  = floatval($debit->exchange_rate);
                $creditRate = floatval($credit->exchange_rate);

                /**
                 * 📌 UNIVERSAL REALISED FORMULA (client-approved)
                 *
                 *  CUSTOMER:  Realised = Receipt - Sale
                 *  SUPPLIER: Realised = Purchase - Payment
                 *
                 *  debit  = sale/payment
                 *  credit = receipt/purchase
                 *
                 *  So realised = creditRate – debitRate (always correct)
                 */
                $realised = round(($creditRate - $debitRate) * $matchAmount, 4);

                // Insert match record
                ForexMatch::create([
                    'party_id' => $partyId,
                    'ledger_type' => $ledgerType,
                    'base_currency_id' => $baseCurrencyId,
                    'debit_txn_id' => $debit->id,
                    'credit_txn_id' => $credit->id,
                    'matched_base_amount' => $matchAmount,
                    'debit_rate' => $debitRate,
                    'credit_rate' => $creditRate,
                    'realised_gain' => $realised > 0 ? $realised : 0,
                    'realised_loss' => $realised < 0 ? abs($realised) : 0,
                ]);

                // Update remaining/settled amounts
                $debit->settled_base_amount  += $matchAmount;
                $debit->remaining_base_amount -= $matchAmount;

                $credit->settled_base_amount  += $matchAmount;
                $credit->remaining_base_amount -= $matchAmount;

                // Apply realised to both sides
                if ($realised > 0) {
                    $debit->realised_gain  += $realised;
                    $credit->realised_gain += $realised;
                } else {
                    $loss = abs($realised);
                    $debit->realised_loss  += $loss;
                    $credit->realised_loss += $loss;
                }

                $debit->save();
                $credit->save();

                Log::info("Matched: debit={$debit->id} credit={$credit->id} amount=$matchAmount realised=$realised");

                if ($debit->remaining_base_amount <= 0) $di++;
                if ($credit->remaining_base_amount <= 0) $ci++;
            }

            // Closing rate for unrealised
            $closingRate = $closingRateGlobal ?? $this->getClosingRateForCurrency($baseCurrencyId);
            $closingRate = floatval($closingRate);

            // Unrealised for remaining txns
            $remainingTxns = ForexRemittance::where('party_id', $partyId)
                ->where('ledger_type', $ledgerType)
                ->where('base_currency_id', $baseCurrencyId)
                ->where('remaining_base_amount', '>', 0)
                ->get();

            foreach ($remainingTxns as $txn) {
                $remaining = floatval($txn->remaining_base_amount);

                // Default invoiceRate = txn's own exchange rate
                $invoiceRate = floatval($txn->exchange_rate);

                // SPECIAL CASE:
                // If this is a receipt/payment and it has remaining exposure,
                // the invoice/exposure rate is the rate of the matched debit (sale/purchase).
                // So try to fetch the last matched debit_rate from forex_matches.
                if (in_array($txn->voucher_type, ['receipt', 'payment']) && $remaining > 0) {
                    $lastMatch = ForexMatch::where('credit_txn_id', $txn->id)
                        ->orderBy('id', 'desc')
                        ->first();
                    if ($lastMatch) {
                        $invoiceRate = floatval($lastMatch->debit_rate);
                    }
                    // else fall back to txn->exchange_rate (already set)
                }

                $unreal = round(($closingRate - $invoiceRate) * $remaining, 4);

                $txn->unrealised_gain = $unreal > 0 ? $unreal : 0;
                $txn->unrealised_loss = $unreal < 0 ? abs($unreal) : 0;
                $txn->closing_rate    = $closingRate;
                $txn->save();

                Log::info("Unrealised: id={$txn->id} rem=$remaining invoiceRate=$invoiceRate unreal=$unreal");
            }


            // Fully settled txns: clear unrealised
            $doneTxns = ForexRemittance::where('party_id', $partyId)
                ->where('ledger_type', $ledgerType)
                ->where('base_currency_id', $baseCurrencyId)
                ->where('remaining_base_amount', '<=', 0)
                ->get();

            foreach ($doneTxns as $txn) {
                $txn->unrealised_gain = 0;
                $txn->unrealised_loss = 0;
                $txn->closing_rate = $closingRate;
                $txn->save();
            }
        }, 5);

        Log::info("===== FIFO END =====");
    }

    /**
     * Determine closing rate fallback.
     */
    protected function getClosingRateForCurrency(int $currencyId)
    {
        $c = Currency::find($currencyId);
        return $c ? floatval($c->exchange_rate) : 0;
    }
}
