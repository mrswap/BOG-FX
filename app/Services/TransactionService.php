<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\ForexRate;
use App\Services\MatchingEngine;
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
     * Create transaction + auto-calc local amount + auto-fill closing_rate (priority manual->resolver)
     *
     * Accepts optional 'closing_rate_override' in $data for SPECIAL CASE
     */
    public function create(array $data): Transaction
    {
        // compute local_amount if not passed
        if (empty($data['local_amount']) && isset($data['base_amount'], $data['exchange_rate'])) {
            $data['local_amount'] = round($data['base_amount'] * $data['exchange_rate'], 4);
        }

        // cast numeric values
        if (isset($data['base_amount'])) $data['base_amount'] = (float)$data['base_amount'];
        if (isset($data['exchange_rate'])) $data['exchange_rate'] = (float)$data['exchange_rate'];

        // If user provided explicit closing_rate in form -> keep it (highest priority)
        if (!empty($data['closing_rate'])) {
            $data['closing_rate'] = (float)$data['closing_rate'];
        }

        // Ensure transaction_date exists
        if (empty($data['transaction_date'])) {
            $data['transaction_date'] = date('Y-m-d');
        }

        // Persist transaction inside DB transaction (safety)
        DB::beginTransaction();
        try {
            $tx = Transaction::create($data);

            // If no manual closing_rate provided, resolve using RateResolver
            if (empty($tx->closing_rate)) {
                $calculated = $this->rateResolver->getClosingRate($tx);
                if (!is_null($calculated)) {
                    $tx->closing_rate = (float)$calculated;
                    // avoid touching user-closing when user provided one
                    $tx->save();
                }
            }

            // Update/insert party-day weighted rate (persist for re-use) - optional but useful
            $this->updatePartyDailyRate($tx);

            // Trigger matching (synchronous)
            $this->matchingEngine->process($tx);

            DB::commit();
            return $tx;
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error("TransactionService::create error: " . $e->getMessage(), [
                'payload' => $data, 'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Update or insert party-date weighted average into forex_rates table.
     *
     * This supports the fallback where market rates are missing.
     */
    protected function updatePartyDailyRate(Transaction $tx): void
    {
        try {
            $baseId  = $tx->base_currency_id;
            $localId = $tx->local_currency_id;
            $partyId = $tx->party_id;
            $date    = $tx->transaction_date;

            if (empty($baseId) || empty($localId) || empty($partyId) || empty($date)) {
                return;
            }

            $txs = Transaction::where('party_id', $partyId)
                ->where('transaction_date', $date)
                ->where('base_currency_id', $baseId)
                ->where('local_currency_id', $localId)
                ->get(['base_amount', 'exchange_rate']);

            if ($txs->isEmpty()) return;

            $totalBase = 0.0;
            $weighted = 0.0;
            foreach ($txs as $t) {
                $totalBase += (float)$t->base_amount;
                $weighted += ((float)$t->base_amount * (float)$t->exchange_rate);
            }

            if ($totalBase <= 0) return;

            $avgRate = $weighted / $totalBase;

            ForexRate::updateOrCreate([
                'date' => $date,
                'party_id' => $partyId,
                'base_currency_id' => $baseId,
                'local_currency_id' => $localId
            ], [
                'rate' => $avgRate
            ]);
        } catch (\Throwable $e) {
            \Log::warning("updatePartyDailyRate failed: " . $e->getMessage());
        }
    }
}
