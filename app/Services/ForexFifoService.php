<?php
namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexMatch;
use App\Models\Currency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class ForexFifoService
{
    /**
     * Apply FIFO matches for a party + ledger_type + currency.
     *
     * Rules implemented:
     * - Two-way FIFO (credits match oldest debits).
     * - Do NOT transfer leftover from a credit to an already-settled debit.
     * - Realised gain/loss recorded on the invoice (debit) row only.
     * - Leftover credit remains available to match future debits.
     *
     * @param int $partyId
     * @param string $ledgerType
     * @param int $baseCurrencyId
     * @param float|null $closingRateGlobal optional manual closing rate (highest priority)
     */
    public function applyFifoFor(int $partyId, string $ledgerType, int $baseCurrencyId, $closingRateGlobal = null)
    {
        Log::info("===== FIFO START =====");
        Log::info("Party: {$partyId} | LedgerType: {$ledgerType} | Currency: {$baseCurrencyId}");

        // Load only rows with remaining_base_amount > 0 (these are the ones that can participate)
        $debits = ForexRemittance::where([
                'party_id' => $partyId,
                'ledger_type' => $ledgerType,
                'base_currency_id' => $baseCurrencyId,
                'direction' => 'debit'
            ])
            ->where('remaining_base_amount', '>', 0)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $credits = ForexRemittance::where([
                'party_id' => $partyId,
                'ledger_type' => $ledgerType,
                'base_currency_id' => $baseCurrencyId,
                'direction' => 'credit'
            ])
            ->where('remaining_base_amount', '>', 0)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        Log::info("Debits Loaded: ".$debits->count());
        foreach ($debits as $d) {
            Log::info("DEBIT ID {$d->id} | Base={$d->base_amount} | Rem={$d->remaining_base_amount} | Rate={$d->exchange_rate}");
        }

        Log::info("Credits Loaded: ".$credits->count());
        foreach ($credits as $c) {
            Log::info("CREDIT ID {$c->id} | Base={$c->base_amount} | Rem={$c->remaining_base_amount} | Rate={$c->exchange_rate}");
        }

        // Iterate credits and match to oldest debits (FIFO).
        // Important: we intentionally process credits -> debits so incoming payments (credits)
        // consume oldest invoices (debits). When a new debit arrives the same routine will
        // be triggered and will find available credits to match.
        foreach ($credits as $credit) {
            // always work off fresh model for credit
            $credit = ForexRemittance::find($credit->id);
            if (!$credit) continue;

            $creditWorkRem = (float) $credit->remaining_base_amount;
            if ($creditWorkRem <= 0) continue;

            Log::info("PROCESSING CREDIT ID {$credit->id} | WorkRem={$creditWorkRem}");

            foreach ($debits as $debit) {
                // reload debit (fresh)
                $debit = ForexRemittance::find($debit->id);
                if (!$debit || (float)$debit->remaining_base_amount <= 0) {
                    // nothing to match with this debit
                    continue;
                }

                if ($creditWorkRem <= 0) {
                    Log::info("-- Credit {$credit->id} fully consumed. Breaking debit loop.");
                    break;
                }

                $debitRem = (float) $debit->remaining_base_amount;
                if ($debitRem <= 0) continue;

                // amount to match
                $matchBase = min($creditWorkRem, $debitRem);
                if ($matchBase <= 0) continue;

                Log::info("MATCH -> Credit {$credit->id} WITH Debit {$debit->id} FOR Base={$matchBase}");

                // compute local (INR) for matched portion
                $debitLocal  = $matchBase * (float)$debit->exchange_rate;
                $creditLocal = $matchBase * (float)$credit->exchange_rate;
                $diff = $creditLocal - $debitLocal;
                $gain = $diff > 0 ? $diff : 0;
                $loss = $diff < 0 ? abs($diff) : 0;

                Log::info("CALC -> debitLocal={$debitLocal} creditLocal={$creditLocal} diff={$diff} gain={$gain} loss={$loss}");

                // perform DB transaction for this match
                DB::transaction(function() use (&$debit, &$credit, $matchBase, $gain, $loss, $partyId, $ledgerType, $baseCurrencyId) {
                    // reduce debit remaining + increase settled
                    $debit->settled_base_amount = bcadd((float)$debit->settled_base_amount, $matchBase, 4);
                    $debit->remaining_base_amount = bcsub((float)$debit->remaining_base_amount, $matchBase, 4);

                    // record realised on the invoice (debit) row (per client rule)
                    $debit->realised_gain = bcadd((float)$debit->realised_gain, $gain, 4);
                    $debit->realised_loss = bcadd((float)$debit->realised_loss, $loss, 4);

                    $debit->save();

                    // reduce credit remaining + increase settled
                    $credit->settled_base_amount = bcadd((float)$credit->settled_base_amount, $matchBase, 4);
                    $credit->remaining_base_amount = bcsub((float)$credit->remaining_base_amount, $matchBase, 4);

                    // do NOT write realised on credit (we keep realised on invoice rows)
                    $credit->save();

                    // create match audit
                    ForexMatch::create([
                        'party_id' => $partyId,
                        'ledger_type' => $ledgerType,
                        'base_currency_id' => $baseCurrencyId,
                        'debit_txn_id' => $debit->id,
                        'credit_txn_id' => $credit->id,
                        'matched_base_amount' => $matchBase,
                        'debit_rate' => $debit->exchange_rate,
                        'credit_rate' => $credit->exchange_rate,
                        'realised_gain' => $gain,
                        'realised_loss' => $loss,
                    ]);
                }); // end transaction

                // decrement working rem for credit
                $creditWorkRem = bcsub((float)$creditWorkRem, $matchBase, 4);

                // log current remaining values
                $freshDebit = ForexRemittance::find($debit->id);
                $freshCredit = ForexRemittance::find($credit->id);

                Log::info("POST-MATCH -> DebitRem={$freshDebit->remaining_base_amount} | CreditRem={$freshCredit->remaining_base_amount}");
            } // end foreach debits

            // IMPORTANT: do NOT move leftover credit into invoice rows.
            // leftover should remain on credit row for future matching.
        } // end foreach credits

        // --- POST PROCESS: compute UNREALISED for debit rows with remaining > 0 ---
        // closing rate priority: explicit > last txn rate > currency master
        $closingRate = null;
        if (!is_null($closingRateGlobal)) {
            $closingRate = (float) $closingRateGlobal;
        } else {
            $closingRate = ForexRemittance::where([
                'party_id' => $partyId,
                'ledger_type' => $ledgerType,
                'base_currency_id' => $baseCurrencyId
            ])->orderBy('transaction_date', 'desc')->orderBy('id', 'desc')->value('exchange_rate');

            if (!$closingRate) {
                $closingRate = optional(Currency::find($baseCurrencyId))->exchange_rate;
            }
        }

        Log::info("Post-FIFO closingRate used = " . ($closingRate ?? 'NULL'));

        if ($closingRate) {
            $remainingDebits = ForexRemittance::where([
                'party_id' => $partyId,
                'ledger_type' => $ledgerType,
                'base_currency_id' => $baseCurrencyId,
                'direction' => 'debit'
            ])->where('remaining_base_amount', '>', 0)
              ->orderBy('transaction_date', 'asc')
              ->orderBy('id', 'asc')
              ->get();

            foreach ($remainingDebits as $d) {
                $rem = (float)$d->remaining_base_amount;
                $rateDiff = $closingRate - (float)$d->exchange_rate;
                $unreal = round($rateDiff * $rem, 2);

                $d->unrealised_gain = $unreal > 0 ? $unreal : 0;
                $d->unrealised_loss = $unreal < 0 ? abs($unreal) : 0;
                $d->save();

                Log::info("UNREAL -> DebitID {$d->id} Rem={$rem} rateDiff={$rateDiff} unreal={$unreal}");
            }
        }

        Log::info("===== FIFO END =====");
    }
}
