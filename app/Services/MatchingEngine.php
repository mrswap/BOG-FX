<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\ForexMatch;
use Carbon\Carbon;
use DB;

class MatchingEngine
{
    protected $gainLossService;

    public function __construct(GainLossService $gainLossService)
    {
        $this->gainLossService = $gainLossService;
    }

    /**
     * Entry point: match a transaction (invoice or settlement)
     *
     * @param Transaction $tx
     * @return void
     */
    public function process(Transaction $tx)
    {
        // Use DB transaction for safety
        DB::transaction(function () use ($tx) {
            if ($tx->isInvoice()) {
                $this->matchInvoice($tx);
            } else {
                $this->matchSettlement($tx);
            }
        });
    }

    /**
     * Match an invoice against oldest open advances/settlements (FIFO).
     */
    protected function matchInvoice(Transaction $invoice)
    {
        // Find open settlements for same party on opposite bucket
        $oppositeType = $invoice->voucher_type === 'sale' ? 'receipt' : 'payment';

        $remaining = $invoice->base_amount;

        // First, use existing advances (settlements that have no invoice match)
        $openSettlements = Transaction::where('party_id', $invoice->party_id)
            ->where('voucher_type', $oppositeType)
            ->whereRaw('base_amount > COALESCE((SELECT SUM(matched_base) FROM forex_matches WHERE settlement_id = transactions.id),0)')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        foreach ($openSettlements as $settlement) {
            if ($remaining <= 0) break;

            // calculate settlement remaining base
            $settlementMatched = ForexMatch::where('settlement_id', $settlement->id)->sum('matched_base');
            $settlementRemaining = max(0, $settlement->base_amount - $settlementMatched);
            if ($settlementRemaining <= 0) continue;

            $toMatch = min($remaining, $settlementRemaining);

            // compute realised
            $realised = $this->gainLossService->calcRealised(
                $toMatch,
                $invoice->exchange_rate,
                $settlement->exchange_rate,
                $invoice->voucher_type // 'sale' or 'purchase'
            );

            ForexMatch::create([
                'party_id' => $invoice->party_id,
                'invoice_id' => $invoice->id,
                'settlement_id' => $settlement->id,
                'matched_base' => $toMatch,
                'realised_amount' => $realised,
            ]);

            $remaining -= $toMatch;
        }

        // If remaining > 0 => invoice stays open (unrealised will be calculated by LedgerBuilder)
    }

    /**
     * Match incoming settlement (receipt/payment) against oldest open invoices (FIFO).
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

            $invoiceMatched = ForexMatch::where('invoice_id', $invoice->id)->sum('matched_base');
            $invoiceRemaining = max(0, $invoice->base_amount - $invoiceMatched);
            if ($invoiceRemaining <= 0) continue;

            $toMatch = min($remaining, $invoiceRemaining);

            // compute realised
            $realised = $this->gainLossService->calcRealised(
                $toMatch,
                $invoice->exchange_rate,
                $settlement->exchange_rate,
                $invoice->voucher_type
            );

            ForexMatch::create([
                'party_id' => $settlement->party_id,
                'invoice_id' => $invoice->id,
                'settlement_id' => $settlement->id,
                'matched_base' => $toMatch,
                'realised_amount' => $realised,
            ]);

            $remaining -= $toMatch;
        }

        // If remaining > 0 => this settlement becomes an advance (unmatched). LedgerBuilder will show unrealised for advances.
    }

    /**
     * Helper: clear matches related to a transaction (used for edit/delete).
     */
    public function clearMatchesForTransaction(Transaction $tx)
    {
        ForexMatch::where('invoice_id', $tx->id)->orWhere('settlement_id', $tx->id)->delete();
    }
}
