<?php

namespace App\Services;

use App\Models\ForexRemittance;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ForexFifoService
{

    /**
     * Robust FIFO matching for a remittance.
     *
     * Behaviour:
     *  - Use FIFO (transaction_date asc, id asc) across target voucher types defined by priority lists.
     *  - Compute the current open/remaining amount on the $rem (respect existing adjustments).
     *  - Match against all eligible targets (excluding $rem itself), allocate adjusted amounts,
     *    write forex_adjustments rows, compute realised gain/loss invoice-centrically.
     *  - Update both sides' statuses after each insertion.
     *
     * This is safe to call for new records or for an existing remittance that already
     * has some adjustments (it will only operate on the remaining open amount).
     */
    public function processRemittance(ForexRemittance $rem)
    {
        DB::beginTransaction();
        try {
            $partyId = $rem->party_id;
            $type = strtolower($rem->voucher_type);

            // Calculate remaining open amount for THIS remittance (respect existing adjustments)
            // If it's an invoice -> check invoice adjustments; if payment/receipt -> check payment adjustments
            $sumAdjAsInvoice = (float) DB::table('forex_adjustments')->where('invoice_id', $rem->id)->sum('adjusted_base_amount');
            $sumAdjAsPayment = (float) DB::table('forex_adjustments')->where('payment_id', $rem->id)->sum('adjusted_base_amount');

            $isRemInvoice = $this->isInvoiceType($rem->voucher_type);
            $adjustedSoFar = $isRemInvoice ? $sumAdjAsInvoice : $sumAdjAsPayment;
            $remaining = max(0.0, (float)$rem->base_amount - $adjustedSoFar);

            // Nothing to allocate
            if ($remaining <= 0) {
                DB::commit();
                return true;
            }

            // Determine the ordered list of voucher types to try matching to
            $priorityLists = $this->getPriorityLists($type);

            foreach ($priorityLists as $targetVoucherType) {
                if ($remaining <= 0) break;

                // Fetch candidate targets for matching (exclude the current remittance itself)
                $targets = ForexRemittance::where('party_id', $partyId)
                    ->where('voucher_type', $targetVoucherType)
                    ->where('id', '!=', $rem->id)
                    ->orderBy('transaction_date', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                foreach ($targets as $target) {
                    if ($remaining <= 0) break;

                    // Compute how much of the target is already used (as invoice or payment)
                    $usedOnTargetAsInvoice = (float) DB::table('forex_adjustments')->where('invoice_id', $target->id)->sum('adjusted_base_amount');
                    $usedOnTargetAsPayment = (float) DB::table('forex_adjustments')->where('payment_id', $target->id)->sum('adjusted_base_amount');

                    // Determine the open amount on the target depending on its role
                    $targetIsInvoice = $this->isInvoiceType($target->voucher_type);
                    $targetOpen = $targetIsInvoice
                        ? max(0.0, (float)$target->base_amount - $usedOnTargetAsInvoice)
                        : max(0.0, (float)$target->base_amount - $usedOnTargetAsPayment);

                    if ($targetOpen <= 0) {
                        // nothing left on this target
                        continue;
                    }

                    // Determine which object is invoice and which is payment for this pair
                    // We need $invoice to always be an invoice (purchase/sale) and $payment to be payment/receipt.
                    if ($targetIsInvoice && !$isRemInvoice) {
                        // target is invoice, rem is payment -> perfect
                        $invoice = $target;
                        $payment = $rem;
                    } elseif ($isRemInvoice && !$targetIsInvoice) {
                        // rem is invoice, target is payment -> perfect
                        $invoice = $rem;
                        $payment = $target;
                    } else {
                        // Both sides are invoices OR both are payments/receipts -> cannot match here
                        // (for example sale vs purchase are both invoices; payments vs receipts are both "payments" side).
                        // Skip to next target.
                        continue;
                    }

                    // Recompute targetOpen specifically for the identified role (safety)
                    if ($invoice->id === $target->id) {
                        // target is invoice
                        $usedOnInvoice = (float) DB::table('forex_adjustments')->where('invoice_id', $invoice->id)->sum('adjusted_base_amount');
                        $invoiceOpen = max(0.0, (float)$invoice->base_amount - $usedOnInvoice);
                        $pairOpen = $invoiceOpen;
                    } else {
                        // target is payment
                        $usedOnPayment = (float) DB::table('forex_adjustments')->where('payment_id', $payment->id)->sum('adjusted_base_amount');
                        $paymentOpen = max(0.0, (float)$payment->base_amount - $usedOnPayment);
                        $pairOpen = $paymentOpen;
                    }

                    // Determine how much can be adjusted on this pair (min of remaining and target's open)
                    $adjustable = min($remaining, $pairOpen);
                    if ($adjustable <= 0) continue;

                    // Compute realised gain/loss in invoice-centric manner:
                    // - purchase invoice => gain = (invoice_rate - payment_rate) * adjusted
                    // - sale invoice     => gain = (payment_rate - invoice_rate) * adjusted
                    $invoiceType = strtolower($invoice->voucher_type);
                    if ($invoiceType === 'purchase') {
                        $realised = ($invoice->exchange_rate - $payment->exchange_rate) * $adjustable;
                    } else { // 'sale'
                        $realised = ($payment->exchange_rate - $invoice->exchange_rate) * $adjustable;
                    }

                    // adjusted_local_amount uses invoice.exchange_rate (invoice-centric)
                    $adjustedLocal = round($adjustable * $invoice->exchange_rate, 4);

                    // Insert adjustment row
                    DB::table('forex_adjustments')->insert([
                        'party_id' => $partyId,
                        'invoice_id' => $invoice->id,
                        'payment_id' => $payment->id,
                        'adjusted_base_amount' => round($adjustable, 6),
                        'adjusted_local_amount' => $adjustedLocal,
                        'realised_gain_loss' => round($realised, 4),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Reduce remaining to allocate on the original remittance (if rem was payment and invoice is target,
                    // remaining refers to payment's remaining; if rem was invoice and payment is target, remaining refers to invoice).
                    $remaining -= $adjustable;

                    // Refresh statuses for the two involved remittances (invoice & payment)
                    // This will set gain_loss_type and gain_loss_value properly (realised vs unrealised)
                    try {
                        $this->refreshRemittanceStatus($invoice);
                    } catch (\Throwable $e) {
                        // non-fatal for continuing, but rethrow after loop if needed
                    }

                    try {
                        $this->refreshRemittanceStatus($payment);
                    } catch (\Throwable $e) {
                        // continue
                    }
                } // end foreach target
            } // end foreach priority list

            // Finally refresh the original remittance status (it might have changed due to matches)
            $this->refreshRemittanceStatus($rem);

            DB::commit();
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            // Re-throw so caller (controller) can show the error
            throw $e;
        }
    }

    protected function getPriorityLists(string $type): array
    {
        $t = strtolower($type);

        return match ($t) {
            'payment' => ['purchase', 'sale'],
            'receipt' => ['sale', 'purchase'],
            'purchase' => ['payment', 'receipt'],
            'sale' => ['receipt', 'payment'],
            default => [],
        };
    }

    protected function isInvoiceType(string $vchType): bool
    {
        return in_array(strtolower($vchType), ['purchase', 'sale']);
    }

    /**
     * Recalculate and update remittance's gain_loss_type and gain_loss_value.
     * gain_loss_type: 'realised' if fully settled, otherwise 'unrealised' (if any open amount)
     */
    protected function refreshRemittanceStatus(ForexRemittance $rem)
    {
        $sumAdjAsInvoice = (float) DB::table('forex_adjustments')->where('invoice_id', $rem->id)->sum('adjusted_base_amount');
        $sumAdjAsPayment = (float) DB::table('forex_adjustments')->where('payment_id', $rem->id)->sum('adjusted_base_amount');

        $isInvoice = $this->isInvoiceType($rem->voucher_type);

        $adjusted = $isInvoice ? $sumAdjAsInvoice : $sumAdjAsPayment;
        $open = max(0.0, (float)$rem->base_amount - $adjusted);

        // sum of realised gains from adjustments affecting this remittance (both roles)
        $realisedSum = (float) DB::table('forex_adjustments')
            ->where(function ($q) use ($rem) {
                $q->where('invoice_id', $rem->id)
                    ->orWhere('payment_id', $rem->id);
            })
            ->sum('realised_gain_loss');

        if ($open <= 0.000001) {
            $rem->gain_loss_type = 'realised';
            $rem->gain_loss_value = round($realisedSum, 4);
        } else {
            $rem->gain_loss_type = 'unrealised';
            // store realised portion so far
            $rem->gain_loss_value = round($realisedSum, 4);
        }

        $rem->save();
    }

    /**
     * Compute unrealised gain/loss for a remittance's open (unallocated) base amount
     * using provided closing rate. Returns positive = gain, negative = loss.
     *
     * This function expects a numeric $closingRate (global or transaction). If null, returns 0.0.
     */
    public function computeUnrealisedWithClosing(ForexRemittance $rem, $closingRate): float
    {
        if (is_null($closingRate)) return 0.0;

        $sumAdjInvoice = (float) DB::table('forex_adjustments')->where('invoice_id', $rem->id)->sum('adjusted_base_amount');
        $sumAdjPayment = (float) DB::table('forex_adjustments')->where('payment_id', $rem->id)->sum('adjusted_base_amount');

        $open = 0.0;
        if ($this->isInvoiceType($rem->voucher_type)) {
            $open = max(0.0, (float)$rem->base_amount - $sumAdjInvoice);
        } else {
            $open = max(0.0, (float)$rem->base_amount - $sumAdjPayment);
        }

        if ($open <= 0) return 0.0;

        $closing = (float)$closingRate;

        if (strtolower($rem->voucher_type) === 'purchase') {
            // unrealised = (invoice_rate - closing_rate) * open
            $val = ($rem->exchange_rate - $closing) * $open;
        } elseif (strtolower($rem->voucher_type) === 'sale') {
            // unrealised = (closing - invoice_rate) * open
            $val = ($closing - $rem->exchange_rate) * $open;
        } elseif (strtolower($rem->voucher_type) === 'payment') {
            // payment (we paid earlier) => unrealised = (payment_rate - closing) * open
            $val = ($rem->exchange_rate - $closing) * $open;
        } else { // receipt
            // receipt (we received earlier) => unrealised = (closing - receipt_rate) * open
            $val = ($closing - $rem->exchange_rate) * $open;
        }

        return round($val, 4);
    }

    /**
     * Legacy computeUnrealised kept for compatibility; prefer computeUnrealisedWithClosing where closing provided.
     */
    public function computeUnrealised(ForexRemittance $rem): float
    {
        // Try using rem->closing_rate or rem->avg_rate; if none, return 0
        $closing = $rem->closing_rate ?? $rem->avg_rate ?? null;
        return $this->computeUnrealisedWithClosing($rem, $closing);
    }
}
