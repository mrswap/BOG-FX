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

            $partyTxs = Transaction::where('party_id', $tx->party_id)
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get();

            $this->rebuildForParty($partyTxs);
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

    public function rebuildForParty(
        iterable $txs
    ): void {

        DB::transaction(function () use ($txs) {

            $txs = collect($txs);

            if ($txs->isEmpty()) {
                return;
            }

            $partyId = optional(
                $txs->first()
            )->party_id;

            if (!$partyId) {
                return;
            }

            /*
        |--------------------------------------------------------------------------
        | Delete ONLY auto FIFO matches
        |--------------------------------------------------------------------------
        */

            ForexMatch::where('party_id', $partyId)

                ->where(function ($q) {

                    $q->whereNull('is_manual')
                        ->orWhere('is_manual', 0);
                })

                ->delete();

            /*
        |--------------------------------------------------------------------------
        | Reset advances
        |--------------------------------------------------------------------------
        */

            Transaction::where('party_id', $partyId)

                ->whereIn('voucher_type', [
                    'receipt',
                    'payment'
                ])

                ->update([
                    'advance_remaining' => null
                ]);

            /*
        |--------------------------------------------------------------------------
        | FIFO ordering
        |--------------------------------------------------------------------------
        */

            $txs = $txs
                ->sortBy([
                    ['transaction_date', 'asc'],
                    ['id', 'asc']
                ])
                ->values();

            /*
        |--------------------------------------------------------------------------
        | Re-run FIFO
        |--------------------------------------------------------------------------
        */

            foreach ($txs as $tx) {

                if ($tx->isInvoice()) {

                    $alreadyMatched = (float)
                    ForexMatch::where(
                        'invoice_id',
                        $tx->id
                    )->sum('matched_base');
                } else {

                    $alreadyMatched = (float)
                    ForexMatch::where(
                        'settlement_id',
                        $tx->id
                    )->sum('matched_base');
                }

                $remaining = round(
                    (float) $tx->base_amount
                        - $alreadyMatched,
                    4
                );

                if ($remaining <= 0) {
                    continue;
                }

                $this->autoMatch(
                    $tx,
                    $remaining
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Recompute advances
        |--------------------------------------------------------------------------
        */

            $settlements = Transaction::where(
                'party_id',
                $partyId
            )

                ->whereIn('voucher_type', [
                    'receipt',
                    'payment'
                ])

                ->get();

            foreach ($settlements as $settlement) {

                $matched = (float)
                ForexMatch::where(
                    'settlement_id',
                    $settlement->id
                )->sum('matched_base');

                $remaining = round(
                    (float) $settlement->base_amount
                        - $matched,
                    4
                );

                $settlement->advance_remaining =
                    $remaining > 0
                    ? $remaining
                    : null;

                $settlement->save();
            }

            \Log::info(
                'FIFO rebuild completed',
                [
                    'party_id' => $partyId,
                    'transactions' => $txs->count()
                ]
            );
        });
    }




    public function clearMatchesForTransaction(
        Transaction $tx
    ): void {

        /*
    |--------------------------------------------------------------------------
    | Delete all matches linked to current transaction
    |--------------------------------------------------------------------------
    |
    | During UPDATE:
    | We must remove old manual + auto matches
    | attached to this voucher only.
    |
    */

        ForexMatch::where(function ($q) use ($tx) {

            $q->where('invoice_id', $tx->id)
                ->orWhere('settlement_id', $tx->id);
        })->delete();

        /*
    |--------------------------------------------------------------------------
    | Reset advance remaining
    |--------------------------------------------------------------------------
    */

        if (
            in_array(
                $tx->voucher_type,
                ['receipt', 'payment']
            )
        ) {

            $tx->advance_remaining = null;

            $tx->save();
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
    | INVOICE SIDE
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

                /*
            |--------------------------------------------------------------------------
            | Settlement already matched
            |--------------------------------------------------------------------------
            */

                $matched = (float)
                ForexMatch::where(
                    'settlement_id',
                    $settlement->id
                )->sum('matched_base');

                $open = round(
                    (float) $settlement->base_amount
                        - $matched,
                    4
                );

                if ($open <= 0) {
                    continue;
                }

                /*
            |--------------------------------------------------------------------------
            | Allocate
            |--------------------------------------------------------------------------
            */

                $allocate = min(
                    $remainingAmount,
                    $open
                );

                /*
            |--------------------------------------------------------------------------
            | Realised gain/loss
            |--------------------------------------------------------------------------
            */

                $realised =
                    $this->gainLossService
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
                    (float) $tx->exchange_rate,

                    'settlement_rate' =>
                    (float) $settlement->exchange_rate,

                    'realised_amount' => $realised,

                    'is_manual' => 0,
                ]);

                $remainingAmount = round(
                    $remainingAmount - $allocate,
                    4
                );
            }

            return;
        }

        /*
    |--------------------------------------------------------------------------
    | SETTLEMENT SIDE
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

            /*
        |--------------------------------------------------------------------------
        | Invoice already matched
        |--------------------------------------------------------------------------
        */

            $matched = (float)
            ForexMatch::where(
                'invoice_id',
                $invoice->id
            )->sum('matched_base');

            $open = round(
                (float) $invoice->base_amount
                    - $matched,
                4
            );

            if ($open <= 0) {
                continue;
            }

            /*
        |--------------------------------------------------------------------------
        | Allocate
        |--------------------------------------------------------------------------
        */

            $allocate = min(
                $remainingAmount,
                $open
            );

            /*
        |--------------------------------------------------------------------------
        | Realised gain/loss
        |--------------------------------------------------------------------------
        */

            $realised =
                $this->gainLossService
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
                (float) $invoice->exchange_rate,

                'settlement_rate' =>
                (float) $tx->exchange_rate,

                'realised_amount' => $realised,

                'is_manual' => 0,
            ]);

            $remainingAmount = round(
                $remainingAmount - $allocate,
                4
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Save advance remaining
    |--------------------------------------------------------------------------
    */

        if (
            in_array(
                $tx->voucher_type,
                ['receipt', 'payment']
            )
        ) {

            $tx->advance_remaining =
                $remainingAmount > 0
                ? $remainingAmount
                : null;

            $tx->save();
        }
    }
}