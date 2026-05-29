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

$amount = (float) $row['amount'];

$alreadyMatched = ForexMatch::where(
'settlement_id',
$matchedTx->id
)
->sum('matched_base');

$availableBalance =
(float) $matchedTx->base_amount
- $alreadyMatched;

\Log::info('MATCH BALANCE CHECK', [
'settlement_id' => $matchedTx->id,
'base_amount' => $matchedTx->base_amount,
'already_matched' => $alreadyMatched,
'available_balance' => $availableBalance,
'requested_amount' => $amount
]);

if ($amount > $availableBalance) {


throw new \Exception(
    "Settlement amount exceeds available balance for voucher: "
    . $matchedTx->voucher_no
);

}


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
        Transaction $transaction,
        array $data
    ) {

        \Log::info('TX SERVICE UPDATE START', [
            'transaction_id' => $transaction->id,
            'incoming_manual_matches' => $data['manual_matches'] ?? []
        ]);

        /*
|--------------------------------------------------------------------------
| UPDATE TRANSACTION
|--------------------------------------------------------------------------
*/

        $transaction->update([
            'party_id' => $data['party_id'],
            'party_type' => $data['party_type'] ?? null,
            'transaction_date' => $data['transaction_date'],
            'base_currency_id' => $data['base_currency_id'],
            'base_amount' => $data['base_amount'],
            'closing_rate' => $data['closing_rate'] ?? null,
            'local_currency_id' => $data['local_currency_id'],
            'exchange_rate' => $data['exchange_rate'],
            'local_amount' => $data['local_amount'],
            'voucher_type' => $data['voucher_type'],
            'voucher_no' => $data['voucher_no'],
            'remarks' => $data['remarks'] ?? null,
            'attachment' => $data['attachment']
                ?? $transaction->attachment,
        ]);

        /*
|--------------------------------------------------------------------------
| DELETE OLD MATCHES
|--------------------------------------------------------------------------
*/

        ForexMatch::where('invoice_id', $transaction->id)
            ->orWhere('settlement_id', $transaction->id)
            ->delete();

        \Log::info('OLD MATCHES DELETED', [
            'transaction_id' => $transaction->id
        ]);

        /*
|--------------------------------------------------------------------------
| MANUAL MATCHES
|--------------------------------------------------------------------------
*/

        if (
            !empty($data['manual_matches'])
            &&
            count($data['manual_matches']) > 0
        ) {

            \Log::info('MANUAL MATCHING STARTED', [
                'matches' => $data['manual_matches']
            ]);

            foreach ($data['manual_matches'] as $row) {

                $matchedTx = Transaction::find(
                    $row['transaction_id']
                );

                if (!$matchedTx) {

                    \Log::warning('MATCH TX NOT FOUND', $row);

                    continue;
                }

                $amount = (float) $row['amount'];

                \Log::info('CREATING MANUAL MATCH', [
                    'invoice_id' => $transaction->id,
                    'settlement_id' => $matchedTx->id,
                    'amount' => $amount
                ]);

                ForexMatch::create([

                    'party_id' => $transaction->party_id,

                    'invoice_id' =>
                    $transaction->voucher_type === 'sale'
                        ? $transaction->id
                        : $matchedTx->id,

                    'settlement_id' =>
                    $transaction->voucher_type === 'receipt'
                        ? $transaction->id
                        : $matchedTx->id,

                    'matched_base' => $amount,

                    'invoice_rate' =>
                    $transaction->voucher_type === 'sale'
                        ? $transaction->exchange_rate
                        : $matchedTx->exchange_rate,

                    'settlement_rate' =>
                    $transaction->voucher_type === 'receipt'
                        ? $transaction->exchange_rate
                        : $matchedTx->exchange_rate,

                    'matched_base_amount' => $amount,

                    'realised_amount' => (
                        (
                            $transaction->voucher_type === 'sale'
                            ? $transaction->exchange_rate
                            : $matchedTx->exchange_rate
                        )
                        -
                        (
                            $transaction->voucher_type === 'receipt'
                            ? $transaction->exchange_rate
                            : $matchedTx->exchange_rate
                        )
                    ) * $amount,
                ]);
            }

            \Log::info('MANUAL MATCHING COMPLETED');

            /*
    |--------------------------------------------------------------------------
    | IMPORTANT
    |--------------------------------------------------------------------------
    | STOP HERE
    |--------------------------------------------------------------------------
    */

            return $transaction->fresh();
        }

        /*
|--------------------------------------------------------------------------
| AUTO FIFO MATCHING
|--------------------------------------------------------------------------
*/

        \Log::info('AUTO FIFO MATCHING STARTED');

        app(MatchingEngine::class)
            ->runForParty($transaction->party_id);

        return $transaction->fresh();
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