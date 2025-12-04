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

            $settlementMatched = ForexMatch::where('settlement_id', $settlement->id)->sum('matched_base');
            $settlementRemaining = max(0, $settlement->base_amount - $settlementMatched);
            if ($settlementRemaining <= 0) continue;

            $toMatch = min($remaining, $settlementRemaining);

            $realised = $this->gainLossService->calcRealised(
                $toMatch,
                $invoice->exchange_rate,
                $settlement->exchange_rate,
                $invoice->voucher_type
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

        // invoice remaining left as open (unrealised handled by ledger)
    }

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

        // If remaining > 0 => this settlement becomes an advance (unmatched).
        if ($remaining > 0) {
            // Persist advance metadata on settlement for ledger use (non-destructive)
            $settlement->advance_remaining = $remaining;
            if (!empty($settlement->closing_rate_override)) {
                $settlement->invoice_rate_override = $settlement->closing_rate_override;
            }
            $settlement->save();
        }
    }

    public function clearMatchesForTransaction(Transaction $tx)
    {
        ForexMatch::where('invoice_id', $tx->id)->orWhere('settlement_id', $tx->id)->delete();
    }
}
