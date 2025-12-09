<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\ForexMatch;
use Illuminate\Support\Facades\DB;

class MatchingEngine
{
    protected $gainLossService;

    public function __construct(GainLossService $gainLossService)
    {
        $this->gainLossService = $gainLossService;
    }

    /**
     * USE DURING LIVE CREATE/UPDATE (incremental matching)
     */
    public function process(Transaction $tx)
    {
        DB::transaction(function () use ($tx) {
            if ($tx->isInvoice()) {
                $this->matchInvoice($tx);
            } else {
                $this->matchSettlement($tx);
            }
        });
    }

    /**
     * ========== MATCH INVOICE ==========
     * Invoice = sale/purchase
     * Opposite = receipt/payment
     */
    protected function matchInvoice(Transaction $invoice)
    {
        $oppositeType = $invoice->voucher_type === 'sale' ? 'receipt' : 'payment';
        $remaining = $invoice->base_amount;

        $openSettlements = Transaction::where('party_id', $invoice->party_id)
            ->where('voucher_type', $oppositeType)
            ->whereRaw('base_amount > COALESCE((SELECT SUM(matched_base) FROM forex_matches WHERE settlement_id = transactions.id),0)')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($openSettlements as $settlement) {
            if ($remaining <= 0) break;

            $settMatched = ForexMatch::where('settlement_id', $settlement->id)->sum('matched_base');
            $settRemain = max(0, $settlement->base_amount - $settMatched);
            if ($settRemain <= 0) continue;

            $toMatch = min($remaining, $settRemain);

            $realised = $this->gainLossService->calcRealised(
                $toMatch,
                (float)$invoice->exchange_rate,
                (float)$settlement->exchange_rate,
                $invoice->voucher_type
            );

            ForexMatch::create([
                'party_id'          => $invoice->party_id,
                'invoice_id'        => $invoice->id,
                'settlement_id'     => $settlement->id,

                // NEW proper fields
                'matched_base'          => $toMatch,
                'matched_base_amount'   => $toMatch,
                'invoice_rate'          => (float)$invoice->exchange_rate,
                'settlement_rate'       => (float)$settlement->exchange_rate,

                'realised_amount' => $realised,
            ]);


            $remaining -= $toMatch;
        }
    }

    /**
     * ========== MATCH SETTLEMENT ==========
     * Settlement = receipt/payment
     * Opposite = sale/purchase
     */
    protected function matchSettlement(Transaction $settlement)
    {
        $oppositeType = $settlement->voucher_type === 'receipt' ? 'sale' : 'purchase';
        $remaining = $settlement->base_amount;

        $openInvoices = Transaction::where('party_id', $settlement->party_id)
            ->where('voucher_type', $oppositeType)
            ->whereRaw('base_amount > COALESCE((SELECT SUM(matched_base) FROM forex_matches WHERE invoice_id = transactions.id),0)')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($openInvoices as $invoice) {
            if ($remaining <= 0) break;

            $invMatched = ForexMatch::where('invoice_id', $invoice->id)->sum('matched_base');
            $invRemain = max(0, $invoice->base_amount - $invMatched);
            if ($invRemain <= 0) continue;

            $toMatch = min($remaining, $invRemain);

            $realised = $this->gainLossService->calcRealised(
                $toMatch,
                (float)$invoice->exchange_rate,
                (float)$settlement->exchange_rate,
                $invoice->voucher_type
            );

            ForexMatch::create([
                'party_id'          => $settlement->party_id,
                'invoice_id'        => $invoice->id,
                'settlement_id'     => $settlement->id,

                // NEW proper fields
                'matched_base'          => $toMatch,
                'matched_base_amount'   => $toMatch,
                'invoice_rate'          => (float)$invoice->exchange_rate,
                'settlement_rate'       => (float)$settlement->exchange_rate,

                'realised_amount' => $realised,
            ]);

            $remaining -= $toMatch;
        }

        // SAVE ADVANCE
        if ($remaining > 0) {
            $settlement->advance_remaining = $remaining;
            $settlement->save();
        } else {
            if (!empty($settlement->advance_remaining)) {
                $settlement->advance_remaining = null;
                $settlement->save();
            }
        }
    }

    /**
     * ========== PURE FIFO REBUILD ==========
     *
     * $txs: ordered iterable (chronological, invoice-first) of Transaction models for one party
     */
    /**
     * ========== PURE FIFO REBUILD ==========
     *
     * $txs: ordered iterable (chronological, invoice-first) of Transaction models for one party
     */
    public function rebuildForParty(iterable $txs): void
    {
        DB::transaction(function () use ($txs) {

            // Nothing to do if empty
            $txsArr = is_array($txs) ? $txs : (is_callable([$txs, 'all']) ? $txs->all() : iterator_to_array($txs));
            if (empty($txsArr)) return;

            // Determine party id from first tx (all txs are expected to be same party)
            $first = reset($txsArr);
            $partyId = $first->party_id ?? null;

            // 1) Ensure a clean slate: delete any existing matches for this party
            if (!is_null($partyId)) {
                \App\Models\ForexMatch::where('party_id', $partyId)->delete();
            }

            // 2) Reset advance_remaining on all settlement transactions BEFORE matching
            // This avoids stale advance values interfering with rebuild.
            foreach ($txsArr as $t) {
                if ($t->isSettlement()) {
                    // Only update if not null to avoid unnecessary writes
                    if (!is_null($t->advance_remaining)) {
                        $t->advance_remaining = null;
                        $t->save();
                    }
                }
            }

            // 3) Rebuild matches fresh (FIFO) with support for consuming earlier payment-advances
            $matches = [];

            // Two queues:
            //  - openInvoices: invoices waiting to be settled (fifo)
            //  - openAdvances: earlier settlements (payments) that have remaining -> may be used by later purchases
            $openInvoices = [];
            $openAdvances = [];

            foreach ($txsArr as $tx) {

                if ($tx->isInvoice()) {
                    // Invoice processing
                    $invRemaining = (float)$tx->base_amount;

                    // --- NEW: If this is a PURCHASE, first try to consume earlier PAYMENT advances (FIFO)
                    if ($tx->voucher_type === 'purchase' && !empty($openAdvances)) {
                        $ai = 0;
                        while ($invRemaining > 0 && $ai < count($openAdvances)) {
                            $advEntry = &$openAdvances[$ai];

                            // skip exhausted advances
                            if ($advEntry['remaining'] <= 0) {
                                $ai++;
                                continue;
                            }

                            $advTx = $advEntry['tx'];

                            // Only consider payment-type advances (restrict to purchase-payment flow)
                            if ($advTx->voucher_type !== 'payment') {
                                $ai++;
                                continue;
                            }

                            $toMatch = min($invRemaining, $advEntry['remaining']);
                            if ($toMatch <= 0) {
                                $ai++;
                                continue;
                            }

                            // compute realised using GainLossService (invoice vs settlement/advance)
                            $realised = $this->gainLossService->calcRealised(
                                $toMatch,
                                (float)$tx->exchange_rate,         // invoice rate
                                (float)$advTx->exchange_rate,     // advance (settlement) rate
                                $tx->voucher_type
                            );

                            $matches[] = [
                                'party_id'          => $tx->party_id,
                                'invoice_id'        => $tx->id,
                                'settlement_id'     => $advTx->id,
                                'matched_base'      => $toMatch,
                                'matched_base_amount' => $toMatch,
                                'invoice_rate'      => (float)$tx->exchange_rate,
                                'settlement_rate'   => (float)$advTx->exchange_rate,
                                'realised_amount'   => $realised,
                                'created_at'        => now(),
                                'updated_at'        => now(),
                            ];

                            // decrement counters
                            $invRemaining -= $toMatch;
                            $advEntry['remaining'] -= $toMatch;

                            if ($advEntry['remaining'] <= 0) $ai++;
                        }
                    }

                    // If invoice still has remaining after consuming advances, add to invoice queue
                    if ($invRemaining > 0) {
                        $openInvoices[] = [
                            'tx' => $tx,
                            'remaining' => $invRemaining,
                        ];
                    }

                    continue;
                }

                // Settlement processing (unchanged main flow)
                $settRemaining = (float)$tx->base_amount;
                $i = 0;

                // First match settlement to any open invoices (older invoices) — existing behavior
                while ($settRemaining > 0 && $i < count($openInvoices)) {

                    $invEntry = &$openInvoices[$i];

                    // If invoice exhausted, skip
                    if ($invEntry['remaining'] <= 0) {
                        $i++;
                        continue;
                    }

                    $invoiceTx = $invEntry['tx'];
                    $invRemain = $invEntry['remaining'];

                    // Compute match amount
                    $toMatch = min($settRemaining, $invRemain);
                    if ($toMatch <= 0) {
                        $i++;
                        continue;
                    }

                    // compute realised using GainLossService
                    $realised = $this->gainLossService->calcRealised(
                        $toMatch,
                        (float)$invoiceTx->exchange_rate,
                        (float)$tx->exchange_rate,
                        $invoiceTx->voucher_type
                    );

                    $matches[] = [
                        'party_id'          => $tx->party_id,
                        'invoice_id'        => $invoiceTx->id,
                        'settlement_id'     => $tx->id,
                        'matched_base'      => $toMatch,
                        'matched_base_amount' => $toMatch,
                        'invoice_rate'      => (float)$invoiceTx->exchange_rate,
                        'settlement_rate'   => (float)$tx->exchange_rate,
                        'realised_amount'   => $realised,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // decrement counters
                    $invEntry['remaining'] -= $toMatch;
                    $settRemaining -= $toMatch;

                    if ($invEntry['remaining'] <= 0) $i++;
                }

                // Persist advance_remaining on settlement if something remains
                if ($settRemaining > 0) {
                    // set and save only when changed
                    if ((float) $tx->advance_remaining !== (float) $settRemaining) {
                        $tx->advance_remaining = $settRemaining;
                        $tx->save();
                    }
                    // add to advances queue so future purchases can consume it
                    $openAdvances[] = [
                        'tx' => $tx,
                        'remaining' => $settRemaining,
                    ];
                } else {
                    // ensure previously stored advance is cleared
                    if (!empty($tx->advance_remaining)) {
                        $tx->advance_remaining = null;
                        $tx->save();
                    }
                }
            }

            // 4) Bulk insert all new matches (if any)
            if (!empty($matches)) {
                \App\Models\ForexMatch::insert($matches);
            }

            // 5) Persist any remaining advance_remaining for those settlements still in openAdvances
            foreach ($openAdvances as $adv) {
                $advTx = $adv['tx'];
                $rem = (float)$adv['remaining'];
                if ($rem > 0) {
                    // only save if changed
                    if ((float)$advTx->advance_remaining !== $rem) {
                        $advTx->advance_remaining = $rem;
                        $advTx->save();
                    }
                } else {
                    if (!empty($advTx->advance_remaining)) {
                        $advTx->advance_remaining = null;
                        $advTx->save();
                    }
                }
            }
        });
    }


    public function clearMatchesForTransaction(Transaction $tx)
    {
        ForexMatch::where('invoice_id', $tx->id)
            ->orWhere('settlement_id', $tx->id)
            ->delete();
    }
}
