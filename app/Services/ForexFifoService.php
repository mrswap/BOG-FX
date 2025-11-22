<?php

namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexMatch;
use App\Models\Currency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ForexFifoService
{
    /**
     * Apply FIFO matching and calculate realised / unrealised gains & losses.
     *
     * @param int $partyId
     * @param string $ledgerType 'customer'|'supplier'
     * @param int $baseCurrencyId
     * @param float|null $closingRateGlobal optional override for closing rate
     * @return void
     */
    public function applyFifoFor(int $partyId, string $ledgerType, int $baseCurrencyId, $closingRateGlobal = null)
    {
        Log::info("===== FIFO START =====");
        Log::info("Party: {$partyId} | LedgerType: {$ledgerType} | Currency: {$baseCurrencyId}");

        DB::transaction(function () use ($partyId, $ledgerType, $baseCurrencyId, $closingRateGlobal) {

            // load transactions for this party/ledger/currency ordered for strict FIFO
            $txns = ForexRemittance::where('party_id', $partyId)
                ->where('ledger_type', $ledgerType)
                ->where('base_currency_id', $baseCurrencyId)
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // Partition into debits and credits based on direction field
            $debits = $txns->filter(function ($t) { return $t->direction === 'debit' && $t->remaining_base_amount > 0; })->values();
            $credits = $txns->filter(function ($t) { return $t->direction === 'credit' && $t->remaining_base_amount > 0; })->values();

            $di = 0; $ci = 0;
            while ($di < $debits->count() && $ci < $credits->count()) {
                $debit = $debits[$di];
                $credit = $credits[$ci];

                // how much to match
                $matchAmount = min($debit->remaining_base_amount, $credit->remaining_base_amount);
                if ($matchAmount <= 0) {
                    if ($debit->remaining_base_amount <= 0) $di++;
                    if ($credit->remaining_base_amount <= 0) $ci++;
                    continue;
                }

                // Rates
                $debitRate = floatval($debit->exchange_rate);
                $creditRate = floatval($credit->exchange_rate);

                // Compute realised depending on ledger perspective
                if ($ledgerType === 'customer') {
                    // Sales (debit) matched with Receipts (credit): realised = (receipt_rate - sale_rate) * matched
                    $realised = round(($creditRate - $debitRate) * $matchAmount, 4);
                } else {
                    // Supplier: Purchases (credit) matched with Payments (debit): realised = (payment_rate - purchase_rate) * matched
                    $realised = round(($debitRate - $creditRate) * $matchAmount, 4);
                }

                // Persist match record
                $match = new ForexMatch();
                $match->party_id = $partyId;
                $match->ledger_type = $ledgerType;
                $match->base_currency_id = $baseCurrencyId;
                $match->debit_txn_id = $debit->id;
                $match->credit_txn_id = $credit->id;
                $match->matched_base_amount = $matchAmount;
                $match->debit_rate = $debitRate;
                $match->credit_rate = $creditRate;
                $match->realised_gain = $realised > 0 ? $realised : 0;
                $match->realised_loss = $realised < 0 ? abs($realised) : 0;
                $match->save();

                // Update debit & credit settled/remaining
                $debit->settled_base_amount = round($debit->settled_base_amount + $matchAmount, 4);
                $debit->remaining_base_amount = round($debit->remaining_base_amount - $matchAmount, 4);

                $credit->settled_base_amount = round($credit->settled_base_amount + $matchAmount, 4);
                $credit->remaining_base_amount = round($credit->remaining_base_amount - $matchAmount, 4);

                // Assign realised to both sides (company perspective)
                if ($realised > 0) {
                    $debit->realised_gain = round($debit->realised_gain + $realised, 4);
                    $credit->realised_gain = round($credit->realised_gain + $realised, 4);
                } elseif ($realised < 0) {
                    $loss = abs($realised);
                    $debit->realised_loss = round($debit->realised_loss + $loss, 4);
                    $credit->realised_loss = round($credit->realised_loss + $loss, 4);
                }

                // Save changes
                $debit->save();
                $credit->save();

                Log::info("Matched: debit_id={$debit->id} credit_id={$credit->id} amount={$matchAmount} realised={$realised}");

                // Advance pointers if fully settled
                if ($debit->remaining_base_amount <= 0) $di++;
                if ($credit->remaining_base_amount <= 0) $ci++;
            }

            // After all matching, compute unrealised for remaining open txns using closing rate
            $closingRate = $closingRateGlobal;
            if (is_null($closingRate)) {
                $closingRate = $this->getClosingRateForCurrency($baseCurrencyId);
            }
            $closingRate = floatval($closingRate);

            // Apply unrealised to all remaining txns (both debits and credits)
            $remainingTxns = ForexRemittance::where('party_id', $partyId)
                ->where('ledger_type', $ledgerType)
                ->where('base_currency_id', $baseCurrencyId)
                ->where('remaining_base_amount', '>', 0)
                ->get();

            foreach ($remainingTxns as $txn) {
                $remaining = floatval($txn->remaining_base_amount);
                $invoiceRate = floatval($txn->exchange_rate);

                // Unrealised = (Closing Rate - Invoice Rate) * Remaining
                $unreal = round(($closingRate - $invoiceRate) * $remaining, 4);

                if ($unreal > 0) {
                    $txn->unrealised_gain = $unreal;
                    $txn->unrealised_loss = 0;
                } elseif ($unreal < 0) {
                    $txn->unrealised_gain = 0;
                    $txn->unrealised_loss = abs($unreal);
                } else {
                    $txn->unrealised_gain = 0;
                    $txn->unrealised_loss = 0;
                }

                $txn->avg_rate = $txn->avg_rate ?? null; // keep existing or null
                $txn->closing_rate = $closingRate;
                $txn->save();

                Log::info("Unrealised for txn_id={$txn->id} remaining={$remaining} unreal={$unreal}");
            }

            // For fully settled transactions ensure unrealised is zero (clean-up)
            $fullySettled = ForexRemittance::where('party_id', $partyId)
                ->where('ledger_type', $ledgerType)
                ->where('base_currency_id', $baseCurrencyId)
                ->where('remaining_base_amount', '<=', 0)
                ->get();

            foreach ($fullySettled as $f) {
                // Already have realised populated; clear unrealised fields
                $f->unrealised_gain = 0;
                $f->unrealised_loss = 0;
                $f->closing_rate = $closingRate;
                $f->save();
            }

        }, 5); // retry 5 times on deadlock

        Log::info("===== FIFO END =====");
    }

    /**
     * Fallback to currency exchange_rate as closing rate, or 0 if not found.
     *
     * @param int $currencyId
     * @return float
     */
    protected function getClosingRateForCurrency(int $currencyId)
    {
        $c = Currency::find($currencyId);
        if (! $c) {
            Log::warning("Currency {$currencyId} not found for closing rate fallback. Using 0.");
            return 0;
        }
        return floatval($c->exchange_rate);
    }
}
