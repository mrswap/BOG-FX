<?php

namespace App\Services;

use App\Models\Transaction;
use App\Services\MatchingEngine;
use Carbon\Carbon;

class TransactionService
{
    protected $matchingEngine;

    public function __construct(MatchingEngine $matchingEngine)
    {
        $this->matchingEngine = $matchingEngine;
    }

    public function create(array $data): Transaction
    {
        // compute local_amount if not passed
        if (empty($data['local_amount']) && isset($data['base_amount'], $data['exchange_rate'])) {
            $data['local_amount'] = $data['base_amount'] * $data['exchange_rate'];
        }

        $tx = Transaction::create($data);

        // Trigger matching (synchronous here)
        $this->matchingEngine->process($tx);

        return $tx;
    }

    public function update(Transaction $tx, array $data): Transaction
    {
        // clear existing matches then update then reprocess
        $this->matchingEngine->clearMatchesForTransaction($tx);

        // update fields
        if (isset($data['local_amount']) === false && isset($data['base_amount'], $data['exchange_rate'])) {
            $data['local_amount'] = $data['base_amount'] * $data['exchange_rate'];
        }

        $tx->update($data);

        // Re-run matching for whole bucket (safe approach: rebuild bucket)
        $this->rebuildBucket($tx->party_id, $tx->voucher_type);

        return $tx;
    }

    public function delete(Transaction $tx): void
    {
        $this->matchingEngine->clearMatchesForTransaction($tx);
        $tx->delete();

        // Rebuild bucket for party
        $this->rebuildBucket($tx->party_id, $tx->voucher_type);
    }

    /**
     * Rebuild matching for the whole bucket for a party (safe, brute-force).
     */
    protected function rebuildBucket(int $partyId, string $voucherType)
    {
        // Determine bucket: sale/receipt group vs purchase/payment group
        // We will process all transactions of party ordered by date for both invoice & settlement types
        // Clear matches for party
        \App\Models\ForexMatch::where('party_id', $partyId)->delete();

        // Re-run matching in chronological order:
        $txs = Transaction::where('party_id', $partyId)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($txs as $tx) {
            $this->matchingEngine->process($tx);
        }
    }
}
