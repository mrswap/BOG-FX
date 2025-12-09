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

    /**
     * Create transaction + calculate closing + update daily rate + MATCH or REBUILD
     */
    public function create(array $data): Transaction
    {
        // local amount
        if (empty($data['local_amount']) && isset($data['base_amount'], $data['exchange_rate'])) {
            $data['local_amount'] = round($data['base_amount'] * $data['exchange_rate'], 4);
        }

        // ensure numeric
        $data['base_amount'] = (float)$data['base_amount'];
        $data['exchange_rate'] = (float)$data['exchange_rate'];

        // date fallback
        if (empty($data['transaction_date'])) {
            $data['transaction_date'] = date('Y-m-d');
        }

        DB::beginTransaction();
        try {
            // save
            $tx = Transaction::create($data);

            // closing rate
            if (empty($tx->closing_rate)) {
                $resolved = $this->rateResolver->getClosingRate($tx);
                if (!is_null($resolved)) {
                    $tx->closing_rate = $resolved;
                    $tx->save();
                }
            }

            // update daily forex_rates (party wise avg)
            $this->updatePartyDailyRate($tx);

            // BACKDATE CHECK → REBUILD whole bucket
            $maxDate = Transaction::where('party_id', $tx->party_id)
                ->where('id', '!=', $tx->id)
                ->max('transaction_date');

            if ($maxDate && $tx->transaction_date < $maxDate) {

                // FULL REBUILD (correct FIFO)
                $this->rebuildBucket($tx->party_id);
            } else {

                // normal
                $this->matchingEngine->process($tx);
            }

            DB::commit();
            return $tx;
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error("TransactionService::create error: " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Update transaction (forces full rebuild)
     */
    public function update(Transaction $tx, array $data): Transaction
    {
        DB::beginTransaction();
        try {
            // remove old matches
            $this->matchingEngine->clearMatchesForTransaction($tx);

            // update
            $tx->update($data);

            // closing rate update (if needed)
            if (empty($tx->closing_rate)) {
                $tx->closing_rate = $this->rateResolver->getClosingRate($tx);
                $tx->save();
            }

            // recompute party daily rate
            $this->updatePartyDailyRate($tx);

            // FULL rebuild after update
            $this->rebuildBucket($tx->party_id);

            DB::commit();
            return $tx;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }


    /**
     * Delete transaction (forces full rebuild)
     */
    public function delete(Transaction $tx): void
    {
        DB::beginTransaction();
        try {
            $this->matchingEngine->clearMatchesForTransaction($tx);
            $partyId = $tx->party_id;
            $tx->delete();


            // ----------------- ORPHAN FOREX_RATES CLEANUP (safe, targeted) -----------------
            // Delete any forex_rates for this party that no longer have any matching transactions
            // (match on date + base_currency_id + local_currency_id).
            \Log::info("TransactionService: cleaning orphan forex_rates for party", ['party_id' => $partyId]);

            \App\Models\ForexRate::where('party_id', $partyId)
                ->whereNotExists(function ($query) {
                    $query->select(\DB::raw(1))
                        ->from('transactions')
                        ->whereColumn('transactions.transaction_date', 'forex_rates.date')
                        ->whereColumn('transactions.base_currency_id', 'forex_rates.base_currency_id')
                        ->whereColumn('transactions.local_currency_id', 'forex_rates.local_currency_id')
                        ->whereColumn('transactions.party_id', 'forex_rates.party_id');
                })->delete();

            // FULL rebuild after deletion
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
        ForexMatch::where('party_id', $partyId)->delete();

        // get ordered transactions
        $txs = Transaction::where('party_id', $partyId)
            ->orderBy('transaction_date')
            ->orderByRaw("CASE WHEN voucher_type IN ('sale','purchase') THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        // NEW PURE REBUILD (no per-transaction incremental matching)
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
}
