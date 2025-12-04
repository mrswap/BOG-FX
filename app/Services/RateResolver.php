<?php

namespace App\Services;

use App\Models\ForexRate;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class RateResolver
{
    /**
     * Resolve closing rate using priority:
     * 1) User-provided transaction.closing_rate (handled by caller if present)
     * 2) forex_rates exact date (market)
     * 3) forex_rates latest before date
     * 4) party-wise weighted average (auto-generated)
     * 5) fallback to transaction.exchange_rate
     *
     * @param Transaction $tx
     * @return float
     */
    public function getClosingRate(Transaction $tx): float
    {
        // 0) If tx already has manual closing_rate, caller should prefer it.
        if (!empty($tx->closing_rate)) {
            return (float)$tx->closing_rate;
        }

        $baseId  = $tx->base_currency_id;
        $localId = $tx->local_currency_id;
        $partyId = $tx->party_id;
        $date    = $tx->transaction_date ?? now()->toDateString();

        // 1) Exact market closing rate for date
        $rate = ForexRate::getClosingRate($baseId, $localId, $date);
        if (!is_null($rate)) {
            return (float)$rate;
        }

        // 2) Latest before date (lookback)
        $rate = ForexRate::getLatestBefore($baseId, $localId, $date);
        if (!is_null($rate)) {
            return (float)$rate;
        }

        // 3) Party-wise weighted average for that date (auto-generate)
        $rate = $this->getPartyWeightedAvg($partyId, $date, $baseId, $localId);
        if (!is_null($rate)) {
            // Persist this as a party-level forex_rates row for audit & reuse
            try {
                ForexRate::updateOrCreate([
                    'date' => $date,
                    'party_id' => $partyId,
                    'base_currency_id' => $baseId,
                    'local_currency_id' => $localId
                ], [
                    'rate' => $rate
                ]);
            } catch (\Throwable $e) {
                // non-fatal - log and continue
                Log::warning("Could not persist party-weighted forex_rate: " . $e->getMessage());
            }
            return (float)$rate;
        }

        // 4) Fallback to transaction exchange_rate
        return (float)$tx->exchange_rate;
    }

    /**
     * Compute party-wise weighted average exchange_rate on a date for currency pair.
     *
     * @param int|null $partyId
     * @param string $date
     * @param int|null $baseId
     * @param int|null $localId
     * @return float|null
     */
    protected function getPartyWeightedAvg($partyId, string $date, $baseId, $localId): ?float
    {
        if (empty($partyId) || empty($date) || empty($baseId) || empty($localId)) {
            return null;
        }

        $txs = \App\Models\Transaction::where('party_id', $partyId)
            ->where('transaction_date', $date)
            ->where('base_currency_id', $baseId)
            ->where('local_currency_id', $localId)
            ->get(['base_amount', 'exchange_rate']);

        if ($txs->isEmpty()) return null;

        $totalBase = 0.0;
        $weighted = 0.0;

        foreach ($txs as $t) {
            $base = (float)$t->base_amount;
            $r = (float)$t->exchange_rate;
            $totalBase += $base;
            $weighted += ($base * $r);
        }

        if ($totalBase <= 0) return null;

        return $weighted / $totalBase;
    }
}
