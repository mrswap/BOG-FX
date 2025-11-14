<?php
namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexAdjustment;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ForexFifoService
{
    /**
     * Process a remittance (invoice/payment/receipt/sale/purchase)
     * - creates forex_adjustments records for every FIFO allocation
     * - computes realised gain/loss per allocation
     * - updates related remittance rows' gain_loss_type/value
     *
     * Rules implemented:
     *  - FIFO per party by transaction_date ASC, then id ASC
     *  - Primary matching:
     *      payment  -> purchase invoices first, then sale invoices (cross-netting)
     *      receipt  -> sale invoices first, then purchase invoices
     *      purchase -> payments then receipts (oldest first)
     *      sale     -> receipts then payments (oldest first)
     *  - Realised gain/loss formula:
     *      if invoice is 'purchase': gain = (invoice_rate - payment_rate) * adjusted_base_amount
     *      if invoice is 'sale'    : gain = (payment_rate - invoice_rate) * adjusted_base_amount
     *  - adjusted_local_amount uses the invoice.exchange_rate (invoice-centric view)
     *  - Unrealised computed using closing_rate (if provided) otherwise avg_rate when open
     */
    public function processRemittance(ForexRemittance $rem)
    {
        DB::beginTransaction();
        try {
            $partyId = $rem->party_id;
            $type = strtolower($rem->voucher_type);
            $remaining = (float)$rem->base_amount; // base currency units to allocate

            // helper: get FIFO list of potential targets, in priority order
            $priorityLists = $this->getPriorityLists($type);

            foreach ($priorityLists as $targetVoucherType) {
                if ($remaining <= 0) break;

                // when rem is a payment/receipt -> target are invoices (purchase/sale)
                // when rem is an invoice -> target are payments/receipts (payment/receipt)
                $targets = ForexRemittance::where('party_id', $partyId)
                    ->where('voucher_type', $targetVoucherType)
                    ->orderBy('transaction_date', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                foreach ($targets as $target) {
                    if ($remaining <= 0) break;

                    // compute how much of target already adjusted
                    $usedOnTarget = (float) DB::table('forex_adjustments')
                        ->where(function($q) use ($target) {
                            // target might be invoice or payment
                            $q->where('invoice_id', $target->id)
                              ->orWhere('payment_id', $target->id);
                        })
                        ->sum('adjusted_base_amount');

                    $targetOpen = max(0.0, (float)$target->base_amount - $usedOnTarget);
                    if ($targetOpen <= 0) continue;

                    $adjusted = min($remaining, $targetOpen);
                    $remaining -= $adjusted;

                    // determine which one is invoice and which is payment in this pair
                    // We want $invoice to always be the invoice-side remittance (purchase or sale)
                    if ($this->isInvoiceType($target->voucher_type)) {
                        $invoice = $target;
                        $payment = $rem;
                    } elseif ($this->isInvoiceType($rem->voucher_type)) {
                        $invoice = $rem;
                        $payment = $target;
                    } else {
                        // both are payments/receipts or both invoices -> skip (shouldn't happen)
                        continue;
                    }

                    // compute realised gain/loss (invoice-centric formulas)
                    if (strtolower($invoice->voucher_type) === 'purchase') {
                        // purchase: gain = (invoice_rate - payment_rate) * adjusted
                        $gain = ($invoice->exchange_rate - $payment->exchange_rate) * $adjusted;
                    } else { // sale
                        // sale: gain = (payment_rate - invoice_rate) * adjusted
                        $gain = ($payment->exchange_rate - $invoice->exchange_rate) * $adjusted;
                    }

                    // adjusted_local_amount: use invoice.exchange_rate (invoice-centric)
                    $adjustedLocal = round($adjusted * $invoice->exchange_rate, 4);

                    DB::table('forex_adjustments')->insert([
                        'party_id' => $partyId,
                        'invoice_id' => $invoice->id,
                        'payment_id' => $payment->id,
                        'adjusted_base_amount' => round($adjusted, 6),
                        'adjusted_local_amount' => $adjustedLocal,
                        'realised_gain_loss' => round($gain, 4),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // update invoice/payment rows' gain_loss_type/value where fully settled
                    $this->refreshRemittanceStatus($invoice);
                    $this->refreshRemittanceStatus($payment);
                }
            }

            // After matching, set current remittance's gain_loss_type/value (it may be partially/unapplied)
            $this->refreshRemittanceStatus($rem);

            DB::commit();
            return true;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Returns priority lists of voucher types to attempt matching (in order).
     */
    protected function getPriorityLists(string $type): array
    {
        // normalize
        $t = strtolower($type);

        return match ($t) {
            'payment' => ['purchase', 'sale'],   // pay -> settle purchases first, then sales (cross-net)
            'receipt' => ['sale', 'purchase'],   // receive -> settle sales first, then purchases
            'purchase' => ['payment', 'receipt'],// invoice purchase -> match payments then receipts (advances)
            'sale' => ['receipt', 'payment'],    // invoice sale -> match receipts then payments
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

        // total adjusted vs base depending on role
        // If rem is invoice -> check invoice adjustments; if payment/receipt -> check payment adjustments
        $isInvoice = $this->isInvoiceType($rem->voucher_type);

        $adjusted = $isInvoice ? $sumAdjAsInvoice : $sumAdjAsPayment;
        $open = max(0.0, (float)$rem->base_amount - $adjusted);

        // sum of realised gains from adjustments affecting this remittance (both roles)
        $realisedSum = (float) DB::table('forex_adjustments')
            ->where(function($q) use ($rem) {
                $q->where('invoice_id', $rem->id)
                  ->orWhere('payment_id', $rem->id);
            })
            ->sum('realised_gain_loss');

        if ($open <= 0.000001) {
            $rem->gain_loss_type = 'realised';
            $rem->gain_loss_value = round($realisedSum, 4);
        } else {
            $rem->gain_loss_type = 'unrealised';
            // For open amounts we store realised portion only; unrealised will be computed on reports using closing_rate/avg_rate.
            $rem->gain_loss_value = round($realisedSum, 4);
        }

        $rem->save();
    }

    /**
     * Compute unrealised gain/loss for a remittance's open (unallocated) base amount
     * using closing_rate if provided, otherwise avg_rate if provided, else null.
     *
     * Returns float() (positive = gain, negative = loss)
     */
    public function computeUnrealised(ForexRemittance $rem): float
    {
        $sumAdjInvoice = (float) DB::table('forex_adjustments')->where('invoice_id', $rem->id)->sum('adjusted_base_amount');
        $sumAdjPayment = (float) DB::table('forex_adjustments')->where('payment_id', $rem->id)->sum('adjusted_base_amount');

        $open = 0.0;
        if ($this->isInvoiceType($rem->voucher_type)) {
            $open = max(0.0, (float)$rem->base_amount - $sumAdjInvoice);
        } else {
            $open = max(0.0, (float)$rem->base_amount - $sumAdjPayment);
        }

        if ($open <= 0) return 0.0;

        // choose rate for unrealised:
        $closing = $rem->closing_rate ?? $rem->avg_rate ?? null;
        if (is_null($closing)) {
            // no closing/avg -> cannot compute unrealised here; caller should pass closing/avg if needed
            return 0.0;
        }

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
}
