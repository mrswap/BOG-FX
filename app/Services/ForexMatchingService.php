<?php

namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ForexMatchingService
{
    /**
     * Auto-match a given remittance: invoice -> allowed settlements only (V7 rule).
     * Sale ⇄ Receipts only.
     * Purchase ⇄ Payments only.
     */
    public function autoMatchForRemittance(ForexRemittance $remittance)
    {
        Log::info('[autoMatchForRemittance V7] Entered', [
            'id' => $remittance->id,
            'type' => $remittance->voucher_type
        ]);

        $isInvoice = in_array($remittance->voucher_type, ['sale', 'purchase']);

        if ($isInvoice) {
            $this->matchInvoiceAgainstSettlements($remittance);
            return;
        }

        // settlement => match against invoices of the complementary type (only)
        $this->matchSettlementAgainstInvoices($remittance);
    }

    /**
     * Match a single invoice (sale/purchase) against available settlements FIFO.
     * V7: sale -> receipts only ; purchase -> payments only
     */
    protected function matchInvoiceAgainstSettlements(ForexRemittance $invoice)
    {
        $remaining = floatval($invoice->remaining_base_amount);
        if ($remaining <= 0) return;

        // V7: only allowed settlement types
        $targetVoucher = $invoice->voucher_type === 'sale' ? 'receipt' : 'payment';

        $settlements = ForexRemittance::where('party_id', $invoice->party_id)
            ->where('base_currency_id', $invoice->base_currency_id)
            ->where('voucher_type', $targetVoucher)
            ->where('transaction_date', '<=', $invoice->transaction_date)
            ->where('remaining_base_amount', '>', 0)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($settlements as $settlement) {
            if ($remaining <= 0) break;

            $sRem = floatval($settlement->remaining_base_amount);
            if ($sRem <= 0) continue;

            $toMatch = min($remaining, $sRem);

            $invoiceRate = floatval($invoice->exchange_rate ?? 0);
            $settlementRate = floatval($settlement->exchange_rate ?? 0);

            if ($invoice->voucher_type === 'sale') {
                $diff = round($settlementRate - $invoiceRate, 6);
            } else {
                $diff = round($invoiceRate - $settlementRate, 6);
            }

            $matchedLocal = round($toMatch * $settlementRate, 4);
            $realised = round($toMatch * $diff, 4);

            ForexMatch::create([
                'invoice_id' => $invoice->id,
                'settlement_id' => $settlement->id,
                'match_date' => Carbon::now()->toDateString(),
                'matched_base_amount' => $toMatch,
                'matched_local_amount' => $matchedLocal,
                'invoice_rate' => $invoiceRate,
                'settlement_rate' => $settlementRate,
                'realised_gain_loss' => $realised,
            ]);

            // persist updates
            $invoice->settled_base_amount = floatval($invoice->settled_base_amount) + $toMatch;
            $invoice->remaining_base_amount = max(0, floatval($invoice->base_amount) - floatval($invoice->settled_base_amount));
            $invoice->save();

            $settlement->settled_base_amount = floatval($settlement->settled_base_amount) + $toMatch;
            $settlement->remaining_base_amount = max(0, floatval($settlement->base_amount) - floatval($settlement->settled_base_amount));
            $settlement->save();

            $remaining = floatval($invoice->remaining_base_amount);
        }

        // compute weighted closing_rate for fully matched invoice
        if ($invoice->remaining_base_amount == 0) {
            $matches = ForexMatch::where('invoice_id', $invoice->id)->get();
            $totalBase = $matches->sum('matched_base_amount');
            if ($totalBase > 0) {
                $weighted = 0.0;
                foreach ($matches as $m) {
                    $weighted += floatval($m->matched_base_amount) * floatval($m->settlement_rate);
                }
                $invoice->closing_rate = round($weighted / $totalBase, 6);
                $invoice->save();
                Log::info('[autoMatchForRemittance V7] invoice fully matched, closing_rate set', ['invoice_id' => $invoice->id, 'closing' => $invoice->closing_rate]);
            }
        }
    }

    /**
     * Match a single settlement against earlier invoices (FIFO).
     * V7: receipt -> sales only ; payment -> purchases only
     */
    protected function matchSettlementAgainstInvoices(ForexRemittance $settlement)
    {
        $remaining = floatval($settlement->remaining_base_amount);
        if ($remaining <= 0) return;

        $targetVoucher = $settlement->voucher_type === 'receipt' ? 'sale' : 'purchase';

        $invoices = ForexRemittance::where('party_id', $settlement->party_id)
            ->where('base_currency_id', $settlement->base_currency_id)
            ->where('voucher_type', $targetVoucher)
            ->where('transaction_date', '<=', $settlement->transaction_date)
            ->where('remaining_base_amount', '>', 0)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) break;

            $invRem = floatval($invoice->remaining_base_amount);
            if ($invRem <= 0) continue;

            $toMatch = min($remaining, $invRem);

            $invoiceRate = floatval($invoice->exchange_rate ?? 0);
            $settlementRate = floatval($settlement->exchange_rate ?? 0);

            if ($invoice->voucher_type === 'sale') {
                $diff = round($settlementRate - $invoiceRate, 6);
            } else {
                $diff = round($invoiceRate - $settlementRate, 6);
            }

            $matchedLocal = round($toMatch * $settlementRate, 4);
            $realised = round($toMatch * $diff, 4);

            ForexMatch::create([
                'invoice_id' => $invoice->id,
                'settlement_id' => $settlement->id,
                'match_date' => Carbon::now()->toDateString(),
                'matched_base_amount' => $toMatch,
                'matched_local_amount' => $matchedLocal,
                'invoice_rate' => $invoiceRate,
                'settlement_rate' => $settlementRate,
                'realised_gain_loss' => $realised,
            ]);

            // persist
            $invoice->settled_base_amount = floatval($invoice->settled_base_amount) + $toMatch;
            $invoice->remaining_base_amount = max(0, floatval($invoice->base_amount) - floatval($invoice->settled_base_amount));
            $invoice->save();

            $settlement->settled_base_amount = floatval($settlement->settled_base_amount) + $toMatch;
            $settlement->remaining_base_amount = max(0, floatval($settlement->base_amount) - floatval($settlement->settled_base_amount));
            $settlement->save();

            $remaining = floatval($settlement->remaining_base_amount);
        }

        // invoice closing_rate will be computed when invoice becomes fully matched (handled in invoice flow)
    }

    /**
     * Rebuild all matches for a party + base currency using invoice-first FIFO and V7 settlement rules.
     * Caller must handle transactions.
     */
    public function rebuildMatchesForPartyCurrency(int $partyId, int $baseCurrencyId)
    {
        Log::info('[rebuildMatches V7] START', compact('partyId', 'baseCurrencyId'));

        $rows = ForexRemittance::where('party_id', $partyId)
            ->where('base_currency_id', $baseCurrencyId)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $ids = $rows->pluck('id')->toArray();

        ForexMatch::whereIn('invoice_id', $ids)
            ->orWhereIn('settlement_id', $ids)
            ->delete();

        foreach ($rows as $r) {
            $r->settled_base_amount = 0;
            $r->remaining_base_amount = $r->base_amount;
            $r->save();
        }

        $invoices = $rows->filter(fn($r) => in_array($r->voucher_type, ['sale', 'purchase']))->values();
        $settlements = $rows->filter(fn($r) => in_array($r->voucher_type, ['receipt', 'payment']))->values();

        Log::info('[rebuildMatches V7] invoices_count=' . $invoices->count() . ' settlements_count=' . $settlements->count());

        // For each invoice, consume only allowed settlement types (V7)
        foreach ($invoices as $inv) {
            $remaining = floatval($inv->remaining_base_amount);
            if ($remaining <= 0) continue;

            foreach ($settlements as $sett) {
                if ($remaining <= 0) break;

                $sRem = floatval($sett->remaining_base_amount);
                if ($sRem <= 0) continue;

                // V7: sale -> receipt only ; purchase -> payment only
                if ($inv->voucher_type === 'sale' && $sett->voucher_type !== 'receipt') continue;
                if ($inv->voucher_type === 'purchase' && $sett->voucher_type !== 'payment') continue;

                // only use settlements dated <= invoice.date
                if ($sett->transaction_date > $inv->transaction_date) continue;

                $toMatch = min($remaining, $sRem);

                $invoiceRate = floatval($inv->exchange_rate ?? 0);
                $settlementRate = floatval($sett->exchange_rate ?? 0);

                if ($inv->voucher_type === 'sale') {
                    $diff = round($settlementRate - $invoiceRate, 6);
                } else {
                    $diff = round($invoiceRate - $settlementRate, 6);
                }

                $matchedLocal = round($toMatch * $settlementRate, 4);
                $realised = round($toMatch * $diff, 4);

                ForexMatch::create([
                    'invoice_id' => $inv->id,
                    'settlement_id' => $sett->id,
                    'match_date' => Carbon::now()->toDateString(),
                    'matched_base_amount' => $toMatch,
                    'matched_local_amount' => $matchedLocal,
                    'invoice_rate' => $invoiceRate,
                    'settlement_rate' => $settlementRate,
                    'realised_gain_loss' => $realised,
                ]);

                $inv->settled_base_amount = floatval($inv->settled_base_amount) + $toMatch;
                $inv->remaining_base_amount = max(0, floatval($inv->base_amount) - floatval($inv->settled_base_amount));
                $inv->save();

                $sett->settled_base_amount = floatval($sett->settled_base_amount) + $toMatch;
                $sett->remaining_base_amount = max(0, floatval($sett->base_amount) - floatval($sett->settled_base_amount));
                $sett->save();

                $remaining = floatval($inv->remaining_base_amount);
            }

            // If invoice fully matched, compute weighted closing_rate
            if ($inv->remaining_base_amount == 0) {
                $matches = ForexMatch::where('invoice_id', $inv->id)->get();
                $totalBase = $matches->sum('matched_base_amount');
                if ($totalBase > 0) {
                    $weighted = 0.0;
                    foreach ($matches as $m) {
                        $weighted += floatval($m->matched_base_amount) * floatval($m->settlement_rate);
                    }
                    $inv->closing_rate = round($weighted / $totalBase, 6);
                    $inv->save();
                }
            }
        }

        Log::info('[rebuildMatches V7] DONE');
    }
}
