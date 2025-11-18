<?php
namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexMatch;

class ForexFifoService
{
    public function applyFifoFor(int $partyId, string $ledgerType, int $baseCurrencyId)
    {
        $debits = ForexRemittance::where([
            'party_id'       => $partyId,
            'ledger_type'    => $ledgerType,
            'base_currency_id'=> $baseCurrencyId,
            'direction'      => 'debit'
        ])
        ->where('remaining_base_amount', '>', 0)
        ->orderBy('transaction_date')
        ->get();

        $credits = ForexRemittance::where([
            'party_id'       => $partyId,
            'ledger_type'    => $ledgerType,
            'base_currency_id'=> $baseCurrencyId,
            'direction'      => 'credit'
        ])
        ->where('remaining_base_amount', '>', 0)
        ->orderBy('transaction_date')
        ->get();

        foreach ($credits as $credit) {
            $remaining = $credit->remaining_base_amount;

            foreach ($debits as $debit) {
                if ($remaining <= 0) break;
                if ($debit->remaining_base_amount <= 0) continue;

                $matchBase = min($remaining, $debit->remaining_base_amount);

                $debitLocal  = $matchBase * $debit->exchange_rate;
                $creditLocal = $matchBase * $credit->exchange_rate;

                $diff = $creditLocal - $debitLocal;

                $gain = $diff > 0 ? $diff : 0;
                $loss = $diff < 0 ? abs($diff) : 0;

                // Update debit side
                $debit->settled_base_amount += $matchBase;
                $debit->remaining_base_amount -= $matchBase;
                $debit->realised_gain += $gain;
                $debit->realised_loss += $loss;
                $debit->save();

                // Update credit side
                $credit->settled_base_amount += $matchBase;
                $credit->remaining_base_amount -= $matchBase;
                $credit->realised_gain += $gain;
                $credit->realised_loss += $loss;
                $credit->save();

                // Audit match
                ForexMatch::create([
                    'party_id'   => $partyId,
                    'ledger_type'=> $ledgerType,
                    'base_currency_id' => $baseCurrencyId,
                    'debit_txn_id' => $debit->id,
                    'credit_txn_id'=> $credit->id,
                    'matched_base_amount' => $matchBase,
                    'debit_rate'  => $debit->exchange_rate,
                    'credit_rate' => $credit->exchange_rate,
                    'realised_gain'=> $gain,
                    'realised_loss'=> $loss,
                ]);

                $remaining -= $matchBase;
            }
        }
    }
}
