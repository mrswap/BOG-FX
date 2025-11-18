<?php

namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexMatch;
use Illuminate\Support\Facades\Log;

class ForexFifoService
{
    public function applyFifoFor(int $partyId, string $ledgerType, int $baseCurrencyId)
    {
        Log::info("===== FIFO START =====");
        Log::info("Party: $partyId | LedgerType: $ledgerType | Currency: $baseCurrencyId");

        // All open debits (SALE / PURCHASE)
        $debits = ForexRemittance::where([
                'party_id'         => $partyId,
                'ledger_type'      => $ledgerType,
                'base_currency_id' => $baseCurrencyId,
                'direction'        => 'debit',
            ])
            ->where('remaining_base_amount', '>', 0)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        // All open credits (RECEIPT / PAYMENT)
        $credits = ForexRemittance::where([
                'party_id'         => $partyId,
                'ledger_type'      => $ledgerType,
                'base_currency_id' => $baseCurrencyId,
                'direction'        => 'credit',
            ])
            ->where('remaining_base_amount', '>', 0)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        Log::info("Debits Loaded: " . $debits->count());
        foreach ($debits as $d) {
            Log::info("DEBIT ID {$d->id} | Base={$d->base_amount} | Rem={$d->remaining_base_amount} | Rate={$d->exchange_rate}");
        }

        Log::info("Credits Loaded: " . $credits->count());
        foreach ($credits as $c) {
            Log::info("CREDIT ID {$c->id} | Base={$c->base_amount} | Rem={$c->remaining_base_amount} | Rate={$c->exchange_rate}");
        }

        foreach ($credits as $credit) {

            Log::info("PROCESSING CREDIT ID {$credit->id} Remaining={$credit->remaining_base_amount}");

            $remaining = $credit->remaining_base_amount;

            foreach ($debits as $debit) {

                if ($remaining <= 0) {
                    Log::info("-- CREDIT fully settled. Breaking...");
                    break;
                }

                if ($debit->remaining_base_amount <= 0) {
                    Log::info("-- DEBIT ID {$debit->id} has 0 remaining. Skipping.");
                    continue;
                }

                $matchBase = min($remaining, $debit->remaining_base_amount);

                Log::info("MATCHING Credit={$credit->id} WITH Debit={$debit->id} For Base={$matchBase}");

                $debitLocal  = $matchBase * $debit->exchange_rate;
                $creditLocal = $matchBase * $credit->exchange_rate;

                /**
                 * SIGN LOGIC:
                 *
                 * CUSTOMER (SALE vs RECEIPT):
                 *   diffLocal = creditLocal - debitLocal
                 *   - Receipt @90 vs Sale @89 → +1000 Gain
                 *
                 * SUPPLIER (PURCHASE vs PAYMENT):
                 *   diffLocal = debitLocal - creditLocal
                 *   - Purchase @89, Payment @90 → -1000 Loss
                 *   - Payment @87, Purchase @90 → +3000 Gain
                 */
                if ($ledgerType === 'customer') {
                    $diffLocal = $creditLocal - $debitLocal;
                } else { // supplier
                    $diffLocal = $debitLocal - $creditLocal;
                }

                $gain = $diffLocal > 0 ? $diffLocal : 0;
                $loss = $diffLocal < 0 ? abs($diffLocal) : 0;

                Log::info("GAIN={$gain} | LOSS={$loss}");

                // Update debit: sirf remaining base adjust
                $debit->settled_base_amount   += $matchBase;
                $debit->remaining_base_amount -= $matchBase;
                $debit->save();

                // Update credit: realised gain/loss yahi store hoga
                $credit->settled_base_amount   += $matchBase;
                $credit->remaining_base_amount -= $matchBase;
                $credit->realised_gain         += $gain;
                $credit->realised_loss         += $loss;
                $credit->save();

                Log::info("UPDATED DebitRem={$debit->remaining_base_amount}, CreditRem={$credit->remaining_base_amount}");

                ForexMatch::create([
                    'party_id'           => $partyId,
                    'ledger_type'        => $ledgerType,
                    'base_currency_id'   => $baseCurrencyId,
                    'debit_txn_id'       => $debit->id,
                    'credit_txn_id'      => $credit->id,
                    'matched_base_amount'=> $matchBase,
                    'debit_rate'         => $debit->exchange_rate,
                    'credit_rate'        => $credit->exchange_rate,
                    'realised_gain'      => $gain,
                    'realised_loss'      => $loss,
                ]);

                $remaining -= $matchBase;
                Log::info("Remaining after match = {$remaining}");
            }
        }

        Log::info("===== FIFO END =====");
    }
}
