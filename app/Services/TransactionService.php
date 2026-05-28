<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\ForexRate;
use App\Models\ForexMatch;
use App\Services\MatchingEngine;
use App\Services\RateResolver;
use Carbon\Carbon;
use DB;


class TransactionService
{
    protected $matchingEngine;
    protected $rateResolver;


    public function __construct(MatchingEngine $matchingEngine, RateResolver $rateResolver)
    {
        $this->matchingEngine = $matchingEngine;
        $this->rateResolver = $rateResolver;
    }

    public function create(array $data)
    {
        /*
        |--------------------------------------------------------------------------
        | Extract manual matches
        |--------------------------------------------------------------------------
        */

        $manualMatches =
            $data['manual_matches'] ?? [];

        unset($data['manual_matches']);

        /*
        |--------------------------------------------------------------------------
        | Create transaction
        |--------------------------------------------------------------------------
        */

        $tx = Transaction::create($data);

        /*
        |--------------------------------------------------------------------------
        | Daily rate update
        |--------------------------------------------------------------------------
        */

        $this->updatePartyDailyRate($tx);

        /*
        |--------------------------------------------------------------------------
        | Manual settlement first
        |--------------------------------------------------------------------------
        */

        if (!empty($manualMatches)) {

            $this->processManualMatches(
                $tx,
                $manualMatches
            );
        }

        /*
        |--------------------------------------------------------------------------
        | FIFO remaining
        |--------------------------------------------------------------------------
        */

        $this->runRemainingFifo($tx);

        /*
        |--------------------------------------------------------------------------
        | Fresh return
        |--------------------------------------------------------------------------
        */

        return Transaction::with([
            'matchesAsInvoice',
            'matchesAsSettlement'
        ])->find($tx->id);
    }


    public function processManualMatches(
        Transaction $currentTx,
        array $manualMatches = []
    ) {

        if (empty($manualMatches)) {
            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Current remaining amount
    |--------------------------------------------------------------------------
    */

        $remaining = round(
            (float) $currentTx->base_amount,
            4
        );

        foreach ($manualMatches as $row) {

            /*
        |--------------------------------------------------------------------------
        | Skip invalid rows
        |--------------------------------------------------------------------------
        */

            if (
                empty($row['transaction_id'])
                ||
                empty($row['amount'])
            ) {
                continue;
            }

            $amount = round(
                (float) $row['amount'],
                4
            );

            if ($amount <= 0) {
                continue;
            }

            if ($remaining <= 0) {
                break;
            }

            /*
        |--------------------------------------------------------------------------
        | Find target transaction
        |--------------------------------------------------------------------------
        */

            $targetTx = Transaction::find(
                $row['transaction_id']
            );

            if (!$targetTx) {
                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | Same party validation
        |--------------------------------------------------------------------------
        */

            if (
                (int) $targetTx->party_id
                !==
                (int) $currentTx->party_id
            ) {
                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | Voucher compatibility validation
        |--------------------------------------------------------------------------
        */

            $valid = false;

            if (
                $currentTx->voucher_type === 'receipt'
                &&
                $targetTx->voucher_type === 'sale'
            ) {
                $valid = true;
            }

            if (
                $currentTx->voucher_type === 'payment'
                &&
                $targetTx->voucher_type === 'purchase'
            ) {
                $valid = true;
            }

            if (
                $currentTx->voucher_type === 'sale'
                &&
                $targetTx->voucher_type === 'receipt'
            ) {
                $valid = true;
            }

            if (
                $currentTx->voucher_type === 'purchase'
                &&
                $targetTx->voucher_type === 'payment'
            ) {
                $valid = true;
            }

            if (!$valid) {
                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | Already matched
        |--------------------------------------------------------------------------
        */

            if (
                in_array(
                    $targetTx->voucher_type,
                    ['sale', 'purchase']
                )
            ) {

                $matched = (float)
                $targetTx
                    ->matchesAsInvoice()
                    ->sum('matched_base');
            } else {

                $matched = (float)
                $targetTx
                    ->matchesAsSettlement()
                    ->sum('matched_base');
            }

            /*
        |--------------------------------------------------------------------------
        | Open amount
        |--------------------------------------------------------------------------
        */

            $openAmount = round(
                (float) $targetTx->base_amount
                    - $matched,
                4
            );

            if ($openAmount <= 0) {
                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | Final allocation
        |--------------------------------------------------------------------------
        */

            $allocate = min(
                $amount,
                $openAmount,
                $remaining
            );

            if ($allocate <= 0) {
                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | Determine invoice/settlement
        |--------------------------------------------------------------------------
        */

            if (
                in_array(
                    $currentTx->voucher_type,
                    ['sale', 'purchase']
                )
            ) {

                $invoiceId = $currentTx->id;

                $settlementId = $targetTx->id;

                $invoiceRate =
                    (float) $currentTx->exchange_rate;

                $settlementRate =
                    (float) $targetTx->exchange_rate;

                $voucherType =
                    $currentTx->voucher_type;
            } else {

                $invoiceId = $targetTx->id;

                $settlementId = $currentTx->id;

                $invoiceRate =
                    (float) $targetTx->exchange_rate;

                $settlementRate =
                    (float) $currentTx->exchange_rate;

                $voucherType =
                    $targetTx->voucher_type;
            }

            /*
        |--------------------------------------------------------------------------
        | Calculate realised gain/loss
        |--------------------------------------------------------------------------
        */

            $realised = app(
                \App\Services\GainLossService::class
            )->calcRealised(

                $allocate,
                $invoiceRate,
                $settlementRate,
                $voucherType
            );

            /*
        |--------------------------------------------------------------------------
        | Create manual match
        |--------------------------------------------------------------------------
        */

            ForexMatch::create([

                'party_id' => $currentTx->party_id,

                'invoice_id' => $invoiceId,

                'settlement_id' => $settlementId,

                'matched_base' => $allocate,

                'matched_base_amount' => $allocate,

                'invoice_rate' => $invoiceRate,

                'settlement_rate' => $settlementRate,

                'realised_amount' => $realised,

                'is_manual' => 1,
            ]);

            /*
        |--------------------------------------------------------------------------
        | Reduce remaining
        |--------------------------------------------------------------------------
        */

            $remaining = round(
                $remaining - $allocate,
                4
            );
        }
    }




    /**
     * Update transaction (forces full rebuild)
     */
    public function update(
        Transaction $tx,
        array $data
    ): Transaction {

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Extract manual matches
            |--------------------------------------------------------------------------
            */

            $manualMatches =
                $data['manual_matches'] ?? [];

            unset($data['manual_matches']);

            /*
            |--------------------------------------------------------------------------
            | Remove ONLY current tx matches
            |--------------------------------------------------------------------------
            */

            $this->matchingEngine
                ->clearMatchesForTransaction($tx);

            /*
            |--------------------------------------------------------------------------
            | Update tx
            |--------------------------------------------------------------------------
            */

            $tx->update($data);

            /*
            |--------------------------------------------------------------------------
            | Recompute rate
            |--------------------------------------------------------------------------
            */

            $this->updatePartyDailyRate($tx);

            /*
            |--------------------------------------------------------------------------
            | Manual matching first
            |--------------------------------------------------------------------------
            */

            if (!empty($manualMatches)) {

                $this->processManualMatches(
                    $tx,
                    $manualMatches
                );
            }

            /*
            |--------------------------------------------------------------------------
            | FIFO remaining
            |--------------------------------------------------------------------------
            */

            $this->runRemainingFifo($tx);

            DB::commit();

            return $tx->fresh([
                'matchesAsInvoice',
                'matchesAsSettlement'
            ]);
        } catch (\Throwable $e) {

            DB::rollBack();

            throw $e;
        }
    }


    public function delete(
        Transaction $tx
    ): void {

        DB::beginTransaction();

        try {

            /*
        |--------------------------------------------------------------------------
        | Remove matches
        |--------------------------------------------------------------------------
        */

            $this->matchingEngine
                ->clearMatchesForTransaction($tx);

            $partyId = $tx->party_id;

            /*
        |--------------------------------------------------------------------------
        | Delete transaction
        |--------------------------------------------------------------------------
        */

            $tx->delete();

            /*
        |--------------------------------------------------------------------------
        | Cleanup orphan forex rates
        |--------------------------------------------------------------------------
        */

            ForexRate::where('party_id', $partyId)

                ->whereNotExists(function ($query) {

                    $query->select(DB::raw(1))
                        ->from('transactions')

                        ->whereColumn(
                            'transactions.transaction_date',
                            'forex_rates.date'
                        )

                        ->whereColumn(
                            'transactions.base_currency_id',
                            'forex_rates.base_currency_id'
                        )

                        ->whereColumn(
                            'transactions.local_currency_id',
                            'forex_rates.local_currency_id'
                        )

                        ->whereColumn(
                            'transactions.party_id',
                            'forex_rates.party_id'
                        );
                })

                ->delete();

            /*
        |--------------------------------------------------------------------------
        | FULL FIFO REBUILD
        |--------------------------------------------------------------------------
        */

            $this->rebuildBucket($partyId);

            DB::commit();
        } catch (\Throwable $e) {

            DB::rollBack();

            throw $e;
        }
    }



    /**
     * FULL FIFO REBUILD for one party
     */
    protected function rebuildBucket(int $partyId): void
    {
        // delete all matches
        ForexMatch::whereHas('invoice', function ($q) use ($partyId) {

            $q->where('party_id', $partyId);
        })->orWhereHas('settlement', function ($q) use ($partyId) {

            $q->where('party_id', $partyId);
        })->delete();

        // get ordered transactions
        $txs = Transaction::where('party_id', $partyId)
            ->orderByRaw("
            transaction_date ASC,
            CASE 
                WHEN voucher_type IN ('sale','purchase') THEN 0 
                ELSE 1 
            END ASC,
            id ASC
        ")
            ->get();

        \Log::info("FIFO rebuild triggered", [
            'party_id' => $partyId,
            'count' => $txs->count()
        ]);

        // rebuild
        $this->matchingEngine->rebuildForParty($txs);
    }


    /**
     * Save daily weighted avg rate into forex_rates (party-wise)
     */
    protected function updatePartyDailyRate(Transaction $tx): void
    {
        try {
            $baseId  = $tx->base_currency_id;
            $localId = $tx->local_currency_id;
            $partyId = $tx->party_id;
            $date    = $tx->transaction_date;

            if (!$baseId || !$localId || !$partyId) return;

            $txs = Transaction::where('party_id', $partyId)
                ->where('transaction_date', $date)
                ->where('base_currency_id', $baseId)
                ->where('local_currency_id', $localId)
                ->get(['base_amount', 'exchange_rate']);

            if ($txs->isEmpty()) return;

            $total = $txs->sum('base_amount');
            $weighted = 0;

            foreach ($txs as $t) {
                $weighted += $t->base_amount * $t->exchange_rate;
            }

            if ($total <= 0) return;

            $avg = $weighted / $total;

            ForexRate::updateOrCreate([
                'date' => $date,
                'party_id' => $partyId,
                'base_currency_id' => $baseId,
                'local_currency_id' => $localId
            ], [
                'rate' => $avg
            ]);
        } catch (\Throwable $e) {
            \Log::warning("updatePartyDailyRate: " . $e->getMessage());
        }
    }


    public function autoMatch(
        Transaction $tx,
        float $remainingAmount
    ): void {

        if ($remainingAmount <= 0) {
            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Invoice side
    |--------------------------------------------------------------------------
    */

        if ($tx->isInvoice()) {

            $oppositeType =
                $tx->voucher_type === 'sale'
                ? 'receipt'
                : 'payment';

            $openSettlements = Transaction::where(
                'party_id',
                $tx->party_id
            )

                ->where(
                    'voucher_type',
                    $oppositeType
                )

                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get();

            foreach ($openSettlements as $settlement) {

                if ($remainingAmount <= 0) {
                    break;
                }

                $matched = (float)
                ForexMatch::where(
                    'settlement_id',
                    $settlement->id
                )->sum('matched_base');

                $open = round(
                    $settlement->base_amount
                        - $matched,
                    4
                );

                if ($open <= 0) {
                    continue;
                }

                $allocate = min(
                    $remainingAmount,
                    $open
                );

                /*
            |--------------------------------------------------------------------------
            | Realised gain/loss
            |--------------------------------------------------------------------------
            */

                $realised = $this->gainLossService
                    ->calcRealised(

                        $allocate,

                        (float) $tx->exchange_rate,

                        (float) $settlement->exchange_rate,

                        $tx->voucher_type
                    );

                /*
            |--------------------------------------------------------------------------
            | Create FIFO match
            |--------------------------------------------------------------------------
            */

                ForexMatch::create([

                    'party_id' => $tx->party_id,

                    'invoice_id' => $tx->id,

                    'settlement_id' => $settlement->id,

                    'matched_base' => $allocate,

                    'matched_base_amount' => $allocate,

                    'invoice_rate' =>
                    $tx->exchange_rate,

                    'settlement_rate' =>
                    $settlement->exchange_rate,

                    'realised_amount' => $realised,

                    'is_manual' => 0,
                ]);

                $remainingAmount -= $allocate;
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Settlement side
    |--------------------------------------------------------------------------
    */

        $oppositeType =
            $tx->voucher_type === 'receipt'
            ? 'sale'
            : 'purchase';

        $openInvoices = Transaction::where(
            'party_id',
            $tx->party_id
        )

            ->where(
                'voucher_type',
                $oppositeType
            )

            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($openInvoices as $invoice) {

            if ($remainingAmount <= 0) {
                break;
            }

            $matched = (float)
            ForexMatch::where(
                'invoice_id',
                $invoice->id
            )->sum('matched_base');

            $open = round(
                $invoice->base_amount
                    - $matched,
                4
            );

            if ($open <= 0) {
                continue;
            }

            $allocate = min(
                $remainingAmount,
                $open
            );

            /*
        |--------------------------------------------------------------------------
        | Realised gain/loss
        |--------------------------------------------------------------------------
        */

            $realised = $this->gainLossService
                ->calcRealised(

                    $allocate,

                    (float) $invoice->exchange_rate,

                    (float) $tx->exchange_rate,

                    $invoice->voucher_type
                );

            /*
        |--------------------------------------------------------------------------
        | Create FIFO match
        |--------------------------------------------------------------------------
        */

            ForexMatch::create([

                'party_id' => $tx->party_id,

                'invoice_id' => $invoice->id,

                'settlement_id' => $tx->id,

                'matched_base' => $allocate,

                'matched_base_amount' => $allocate,

                'invoice_rate' =>
                $invoice->exchange_rate,

                'settlement_rate' =>
                $tx->exchange_rate,

                'realised_amount' => $realised,

                'is_manual' => 0,
            ]);

            $remainingAmount -= $allocate;
        }
    }



    /*
|--------------------------------------------------------------------------
| Run Remaining FIFO
|--------------------------------------------------------------------------
|
| Remaining balance after manual allocation
|
*/

    public function runRemainingFifo(
        Transaction $tx
    ): void {

        /*
    |--------------------------------------------------------------------------
    | Fresh model with matches
    |--------------------------------------------------------------------------
    */

        $tx = Transaction::with([
            'matchesAsInvoice',
            'matchesAsSettlement'
        ])->find($tx->id);

        if (!$tx) {
            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Already matched amount
    |--------------------------------------------------------------------------
    */

        if (
            in_array(
                $tx->voucher_type,
                ['sale', 'purchase']
            )
        ) {

            $alreadyMatched = (float)
            $tx->matchesAsInvoice
                ->sum('matched_base');
        } else {

            $alreadyMatched = (float)
            $tx->matchesAsSettlement
                ->sum('matched_base');
        }

        /*
    |--------------------------------------------------------------------------
    | Remaining open amount
    |--------------------------------------------------------------------------
    */

        $remaining = round(
            (float) $tx->base_amount
                - $alreadyMatched,
            4
        );

        /*
    |--------------------------------------------------------------------------
    | Nothing remaining
    |--------------------------------------------------------------------------
    */

        if ($remaining <= 0) {
            return;
        }

        /*
    |--------------------------------------------------------------------------
    | Run FIFO auto settlement
    |--------------------------------------------------------------------------
    */

        $this->matchingEngine->autoMatch(
            $tx,
            $remaining
        );
    }
}