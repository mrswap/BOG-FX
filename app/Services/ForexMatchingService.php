<?php

namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ForexMatchingService
{
    /**
     * Auto-match a given remittance with complementary open entries using FIFO.
     *
     * Important:
     * - This expects to be called inside a DB transaction (store() handles DB::beginTransaction()).
     * - Realised is computed using the universal diff rule:
     *      diff = party_rate - reference_rate
     *   where party_rate is always taken from the settlement side (prefer exchange_rate),
     *   and reference_rate is taken from the invoice side (prefer avg_rate).
     *
     * - Realised (stored) = matched_base_amount * diff
     * - matched_local_amount is computed using settlement rate: matched_base * settlementRate
     *
     * - Invoice types: 'sale', 'purchase'
     * - Settlement types: 'receipt', 'payment'
     *
     * @param ForexRemittance $remittance
     * @return void
     */
    public function autoMatchForRemittance(ForexRemittance $remittance)
    {
        Log::info('[ForexMatchingService@autoMatchForRemittance] Entered', [
            'remittance_id' => $remittance->id,
            'voucher_type' => $remittance->voucher_type
        ]);

        // Determine whether the input remittance is an invoice or settlement
        $isInvoice = in_array($remittance->voucher_type, ['sale', 'purchase']);
        $isSettlement = in_array($remittance->voucher_type, ['receipt', 'payment']);

        if (!$isInvoice && !$isSettlement) {
            Log::warning('[ForexMatchingService@autoMatchForRemittance] Unknown voucher_type - skipping matching', [
                'remittance_id' => $remittance->id,
                'voucher_type' => $remittance->voucher_type
            ]);
            return;
        }

        // Complementary direction
        if ($isSettlement) {
            // receipt settles sale, payment settles purchase (party perspective)
            $targetVoucher = $remittance->voucher_type === 'receipt' ? 'sale' : 'purchase';
        } else {
            // invoice tries to match settlements
            $targetVoucher = $remittance->voucher_type === 'sale' ? 'receipt' : 'payment';
        }

        // Calculate remaining on this remittance
        $remaining = floatval($remittance->remaining_base_amount ?? max(0, floatval($remittance->base_amount) - floatval($remittance->settled_base_amount ?? 0)));
        Log::info('[ForexMatchingService@autoMatchForRemittance] Starting remaining', ['remittance_id' => $remittance->id, 'remaining' => $remaining]);

        if ($remaining <= 0) {
            Log::info('[ForexMatchingService@autoMatchForRemittance] Nothing to match (remaining <= 0)', ['remittance_id' => $remittance->id]);
            return;
        }

        // Find complementary open entries (same party, same base currency)
        $oppositeQuery = ForexRemittance::where('party_id', $remittance->party_id)
            ->where('base_currency_id', $remittance->base_currency_id)
            ->where('voucher_type', $targetVoucher)
            ->whereRaw('(COALESCE(remaining_base_amount, base_amount - COALESCE(settled_base_amount,0))) > 0')
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        Log::info('[ForexMatchingService@autoMatchForRemittance] Opposite query prepared', [
            'remittance_id' => $remittance->id,
            'sql_preview' => $oppositeQuery->toSql(),
        ]);

        $opposites = $oppositeQuery->get();
        Log::info('[ForexMatchingService@autoMatchForRemittance] Found opposites', ['count' => $opposites->count(), 'ids' => $opposites->pluck('id')]);

        // Keep track of invoice IDs that got matched so we can recompute/update closing_rate later
        $touchedInvoiceIds = [];

        foreach ($opposites as $opp) {
            if ($remaining <= 0) break;

            // compute open base on opposite row
            $oppRemaining = floatval($opp->remaining_base_amount ?? max(0, floatval($opp->base_amount) - floatval($opp->settled_base_amount ?? 0)));
            if ($oppRemaining <= 0) {
                Log::info('[ForexMatchingService@autoMatchForRemittance] Skipping opposite with no remaining', ['opp_id' => $opp->id]);
                continue;
            }

            $toMatch = min($remaining, $oppRemaining);

            // Identify roles: ensure invoice and settlement variables are correctly assigned
            if ($isSettlement) {
                // current remittance is settlement; opposite is invoice
                $invoice = $opp;
                $settlement = $remittance;
            } else {
                // current remittance is invoice; opposite is settlement
                $invoice = $remittance;
                $settlement = $opp;
            }

            // Resolve preferred rates per final rules
            // Invoice: prefer avg_rate then exchange_rate
            $invoiceRate = $invoice->avg_rate !== null && floatval($invoice->avg_rate) != 0
                ? floatval($invoice->avg_rate)
                : ( $invoice->exchange_rate !== null ? floatval($invoice->exchange_rate) : 0.0 );

            // Settlement: prefer exchange_rate then avg_rate
            $settlementRate = $settlement->exchange_rate !== null && floatval($settlement->exchange_rate) != 0
                ? floatval($settlement->exchange_rate)
                : ( $settlement->avg_rate !== null ? floatval($settlement->avg_rate) : 0.0 );

            // party_rate = settlementRate (rate provided by party)
            // reference_rate = invoiceRate (our expected/contract rate)
            $partyRate = $settlementRate;
            $referenceRate = $invoiceRate;

            // diff = party_rate - reference_rate (universal rule)
            $diff = round(floatval($partyRate) - floatval($referenceRate), 6);

            // matched local amount (store in local currency) computed via settlement rate
            $matchedLocal = round($toMatch * $settlementRate, 4);

            // realised = matched_base * diff (store sign as-is)
            $realised = round($toMatch * $diff, 4);

            // Create ForexMatch record
            try {
                $match = ForexMatch::create([
                    'invoice_id' => $invoice->id,
                    'settlement_id' => $settlement->id,
                    'match_date' => Carbon::now()->toDateString(),
                    'matched_base_amount' => $toMatch,
                    'matched_local_amount' => $matchedLocal,
                    'invoice_rate' => $invoiceRate,
                    'settlement_rate' => $settlementRate,
                    'realised_gain_loss' => $realised,
                ]);
            } catch (\Throwable $e) {
                // Log and continue; do not crash matching service
                Log::error('[ForexMatchingService@autoMatchForRemittance] Failed to create ForexMatch', [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'invoice_id' => $invoice->id ?? null,
                    'settlement_id' => $settlement->id ?? null,
                ]);
                // try to continue to next opposite
                continue;
            }

            Log::info('[ForexMatchingService@autoMatchForRemittance] Created ForexMatch', [
                'match_id' => $match->id,
                'invoice_id' => $invoice->id,
                'settlement_id' => $settlement->id,
                'to_match' => $toMatch,
                'matched_local' => $matchedLocal,
                'invoice_rate' => $invoiceRate,
                'settlement_rate' => $settlementRate,
                'party_rate' => $partyRate,
                'reference_rate' => $referenceRate,
                'diff' => $diff,
                'realised' => $realised,
            ]);

            // Mark this invoice id for later processing
            $touchedInvoiceIds[] = $invoice->id;

            // Update invoice & settlement settled_base_amount and remaining_base_amount
            try {
                $invoiceSettledBefore = floatval($invoice->settled_base_amount ?? 0);
                $settlementSettledBefore = floatval($settlement->settled_base_amount ?? 0);

                $invoice->settled_base_amount = $invoiceSettledBefore + $toMatch;
                $invoice->remaining_base_amount = max(0, floatval($invoice->base_amount) - $invoice->settled_base_amount);
                $invoice->save();

                $settlement->settled_base_amount = $settlementSettledBefore + $toMatch;
                $settlement->remaining_base_amount = max(0, floatval($settlement->base_amount) - $settlement->settled_base_amount);
                $settlement->save();

                Log::info('[ForexMatchingService@autoMatchForRemittance] Updated remittance settled/remaining', [
                    'invoice_id' => $invoice->id,
                    'invoice_settled_after' => $invoice->settled_base_amount,
                    'invoice_remaining_after' => $invoice->remaining_base_amount,
                    'settlement_id' => $settlement->id,
                    'settlement_settled_after' => $settlement->settled_base_amount,
                    'settlement_remaining_after' => $settlement->remaining_base_amount,
                ]);
            } catch (\Throwable $e) {
                Log::error('[ForexMatchingService@autoMatchForRemittance] Failed to update remittances after match', [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'invoice_id' => $invoice->id,
                    'settlement_id' => $settlement->id,
                ]);
                // continue - do not rollback here (caller controls transaction)
            }

            // After saving, refresh our models so loop calculations remain accurate
            try {
                $remittance->refresh();
                $invoice->refresh();
                $settlement->refresh();
            } catch (\Throwable $e) {
                // refresh might fail in some ORM states; log but continue
                Log::warning('[ForexMatchingService@autoMatchForRemittance] Refresh failed after update', [
                    'exception' => $e->getMessage(),
                ]);
            }

            // Recompute remaining for loop control based on the original remittance id
            $remaining = floatval($remittance->remaining_base_amount ?? max(0, floatval($remittance->base_amount) - floatval($remittance->settled_base_amount ?? 0)));

            Log::info('[ForexMatchingService@autoMatchForRemittance] Loop state after match', [
                'remittance_id' => $remittance->id,
                'current_remaining' => $remaining,
            ]);
        } // end foreach opposites

        /**
         * AFTER-MATCH POST-PROCESSING:
         * For any invoice that was touched in the matching run, if it is now fully matched
         * (remaining_base_amount == 0) compute a weighted settlement rate from ForexMatch and
         * persist it into invoice.closing_rate (rounded to 6 decimals).
         *
         * This ensures ledger closing_rate for fully matched invoices is derived from actual settlements.
         */
        $uniqueInvoiceIds = array_values(array_unique($touchedInvoiceIds));

        if (!empty($uniqueInvoiceIds)) {
            foreach ($uniqueInvoiceIds as $invId) {
                try {
                    $inv = ForexRemittance::find($invId);
                    if (!$inv) {
                        Log::warning('[ForexMatchingService@autoMatchForRemittance] Invoice not found for closing update', ['invoice_id' => $invId]);
                        continue;
                    }

                    // Only update if fully settled
                    $invRemaining = floatval($inv->remaining_base_amount ?? max(0, floatval($inv->base_amount) - floatval($inv->settled_base_amount ?? 0)));
                    if ($invRemaining > 0) {
                        Log::info('[ForexMatchingService@autoMatchForRemittance] Invoice still open - skipping closing update', ['invoice_id' => $invId, 'remaining' => $invRemaining]);
                        continue;
                    }

                    // Gather matches for this invoice
                    $matchesForInvoice = ForexMatch::where('invoice_id', $invId)->get();
                    $totalBase = 0.0;
                    $weightedSettlementRate = 0.0;

                    foreach ($matchesForInvoice as $m) {
                        $mb = floatval($m->matched_base_amount);
                        $sr = floatval($m->settlement_rate);
                        $totalBase += $mb;
                        $weightedSettlementRate += ($mb * $sr);
                    }

                    if ($totalBase > 0) {
                        $effectiveClosingRate = $weightedSettlementRate / $totalBase;
                        $inv->closing_rate = round($effectiveClosingRate, 6);
                        $inv->save();

                        Log::info('[ForexMatchingService@autoMatchForRemittance] Invoice closing_rate updated (weighted) ', [
                            'invoice_id' => $invId,
                            'effective_closing_rate' => $inv->closing_rate,
                            'total_matched_base' => $totalBase
                        ]);
                    } else {
                        Log::info('[ForexMatchingService@autoMatchForRemittance] No matched_base found for invoice when computing closing rate', ['invoice_id' => $invId]);
                    }
                } catch (\Throwable $e) {
                    Log::error('[ForexMatchingService@autoMatchForRemittance] Error while updating invoice closing_rate', [
                        'invoice_id' => $invId,
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    // continue to next invoice id
                }
            }
        }

        Log::info('[ForexMatchingService@autoMatchForRemittance] Completed matching', ['remittance_id' => $remittance->id]);
    }
}
