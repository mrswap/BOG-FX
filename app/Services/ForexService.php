<?php

namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexGainLoss;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class ForexService
{
    protected function floatIsZero($v, $epsilon = 0.0001)
    {
        return abs((float)$v) <= $epsilon;
    }

    public static function convertToLocal(float $baseAmount, float $rate): float
    {
        return round($baseAmount * $rate, 4);
    }

    /**
     * Realised = (settlementRate - bookRate) * base
     */
    public static function realisedGainLoss(float $baseAmount, float $settlementRate, float $bookRate): float
    {
        return round(($settlementRate - $bookRate) * $baseAmount, 4);
    }

    /**
     * Initialize unrealised forex gain/loss for a new remittance
     */
    public function initializeRemittance(ForexRemittance $remittance): void
    {
        ForexGainLoss::create([
            'remittance_id'    => $remittance->id,
            'party_type'       => $remittance->party_type,
            'party_id'         => $remittance->party_id,
            'currency_id'      => $remittance->currency_id,
            'transaction_date' => $remittance->transaction_date,
            'base_amount'      => $remittance->base_amount,
            'amount'           => self::convertToLocal($remittance->base_amount, $remittance->exch_rate),
            'book_rate'        => $remittance->exch_rate,
            'current_rate'     => $remittance->exch_rate,
            'gain_loss_amount' => 0,
            'type'             => 'unrealised',
            'status'           => 'open',
            'created_by'       => $remittance->created_by,
        ]);
    }

    /**
     * Core pairing: apply $source (payment/receipt) against $target (invoice) FIFO chunk.
     *
     * $source and $target are ForexRemittance models (source = payment/receipt, target = invoice)
     * Returns array with applied_base and gain_loss
     */
    public function linkRemittanceToParty(ForexRemittance $source, ForexRemittance $target, ?int $userId = null): array
    {
        try {
            $remainingSource = (float)$source->remaining_base;
            $remainingTarget = (float)$target->remaining_base;

            if ($remainingSource <= 0 || $remainingTarget <= 0) {
                return ['success' => false, 'message' => 'Nothing to apply'];
            }

            $appliedBase = min($remainingSource, $remainingTarget);

            // Convert
            $sourceLocal = self::convertToLocal($appliedBase, $source->exch_rate);
            $targetLocal = self::convertToLocal($appliedBase, $target->exch_rate);

            // ✅ Realised Gain/Loss = (sourceRate - targetRate) * appliedBase
            $realised = self::realisedGainLoss($appliedBase, $source->exch_rate, $target->exch_rate);

            // Record gain/loss
            ForexGainLoss::create([
                'remittance_id'    => $source->id,
                'invoice_id'       => $target->id,
                'invoice_type'     => $target->linked_invoice_type,
                'party_type'       => $source->party_type,
                'party_id'         => $source->party_id,
                'currency_id'      => $source->currency_id,
                'transaction_date' => now(),
                'base_amount'      => $appliedBase,
                'amount'           => $targetLocal,
                'book_rate'        => $target->exch_rate,
                'current_rate'     => $source->exch_rate,
                'gain_loss_amount' => $realised,
                'type'             => 'realised',
                'status'           => 'approved',
                'created_by'       => $userId,
            ]);

            // ✅ Update source/payment
            $source->applied_base += $appliedBase;
            $source->applied_local_amount += $sourceLocal;
            $source->realised_gain_loss += $realised;
            $source->status = $source->remaining_base <= 0 ? 'realised' : 'partial';
            $source->save();

            // ✅ Update target/invoice
            $target->applied_base += $appliedBase;
            $target->applied_local_amount += $targetLocal;
            $target->realised_gain_loss += $realised;
            $target->status = $target->remaining_base <= 0 ? 'realised' : 'partial';
            $target->save();

            // update target (invoice) applied amounts
            $target->applied_base += $appliedBase;
            $target->applied_local_amount += $targetLocal;
            $target->realised_gain_loss += $realised;
            $target->status = $target->remaining_base <= 0 ? 'realised' : 'partial';
            $target->save();

            return [
                'success' => true,
                'applied_base' => $appliedBase,
                'gain_loss' => $realised,
                'remaining_source' => $source->remaining_base,
                'remaining_target' => $target->remaining_base,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * After trying to match a payment against invoices, create one carry-forward if needed.
     * Guard to avoid duplicates.
     */
    protected function createCarryForward(ForexRemittance $rem, string $status = 'unrealised', ?int $userId = null): ?ForexRemittance
    {
        if ($rem->remaining_base <= 0.000001) return null;

        // already exists open unrealised for this remittance?
        $exists = ForexGainLoss::where('remittance_id', $rem->id)
            ->where('type', 'unrealised')
            ->whereIn('status', ['open', 'pending'])
            ->exists();

        if ($exists) return null;

        $carry = ForexRemittance::create([
            'party_id' => $rem->party_id,
            'party_type' => $rem->party_type,
            'currency_id' => $rem->currency_id,
            'base_currency_id' => $rem->base_currency_id,
            'voucher_no' => $rem->voucher_no . '-CF',
            'transaction_date' => $rem->transaction_date,
            'exch_rate' => $rem->exch_rate,
            'base_amount' => $rem->remaining_base,
            'local_amount' => self::convertToLocal($rem->remaining_base, $rem->exch_rate),
            'applied_base' => 0,
            'applied_local_amount' => 0,
            'realised_gain_loss' => 0,
            'unrealised_gain_loss' => 0,
            'linked_invoice_type' => 'advance',
            'remarks' => 'Auto carry-forward (advance)',
            'created_by' => $userId,
        ]);

        ForexGainLoss::create([
            'remittance_id' => $carry->id,
            'invoice_id' => null,
            'invoice_type' => null,
            'base_amount' => $carry->base_amount,
            'amount' => self::convertToLocal($carry->base_amount, $carry->exch_rate),
            'book_rate' => $carry->exch_rate,
            'current_rate' => $carry->exch_rate,
            'gain_loss_amount' => 0,
            'type' => $status,
            'status' => 'open',
            'party_type' => $carry->party_type,
            'party_id' => $carry->party_id,
            'currency_id' => $carry->currency_id,
            'transaction_date' => now(),
            'created_by' => $userId,
        ]);

        return $carry;
    }

    /**
     * Auto-match all remittances for a party using robust FIFO (payments applied to oldest invoices).
     */

    public function autoMatchRemittancesForParty(int $partyId, ?int $userId = null): array
    {
        $summary = ['matches' => 0, 'carry_forwards' => 0, 'gain_loss_total' => 0, 'errors' => []];

        try {
            $remittances = ForexRemittance::where('party_id', $partyId)
                ->with(['gainLoss', 'currency', 'baseCurrency', 'party'])
                ->orderBy('transaction_date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            // ✅ Direction mapping (Accounting view)
            $invoiceDrTypes = ['sale'];         // Customer invoices (Dr)
            $invoiceCrTypes = ['purchase'];     // Supplier invoices (Cr)
            $settlementDrTypes = ['payment'];   // You paid supplier (Dr)
            $settlementCrTypes = ['receipt'];   // Customer paid you (Cr)

            // --- Customer Side Matching (Sale ↔ Receipt) ---
            $sales = $remittances->filter(fn($r) => in_array($r->linked_invoice_type, $invoiceDrTypes))->values();
            $receipts = $remittances->filter(fn($r) => in_array($r->linked_invoice_type, $settlementCrTypes))->values();

            foreach ($receipts as $receipt) {
                foreach ($sales as $sale) {
                    if ($this->floatIsZero($receipt->remaining_base) || $this->floatIsZero($sale->remaining_base)) continue;
                    $res = $this->linkRemittanceToParty($receipt->fresh(), $sale->fresh(), $userId);
                    if (!empty($res['success'])) {
                        $summary['matches']++;
                        $summary['gain_loss_total'] += $res['gain_loss'] ?? 0;
                    }
                }
                if (!$this->floatIsZero($receipt->fresh()->remaining_base)) {
                    $carry = $this->createCarryForward($receipt->fresh(), 'unrealised', $userId);
                    if ($carry) $summary['carry_forwards'] += $carry->base_amount;
                }
            }

            // --- Supplier Side Matching (Purchase ↔ Payment) ---
            $purchases = $remittances->filter(fn($r) => in_array($r->linked_invoice_type, $invoiceCrTypes))->values();
            $payments  = $remittances->filter(fn($r) => in_array($r->linked_invoice_type, $settlementDrTypes))->values();

            foreach ($payments as $payment) {
                foreach ($purchases as $invoice) {
                    // Skip exhausted ones
                    if ($payment->remaining_base <= 0) break;
                    if ($invoice->remaining_base <= 0) continue;

                    // ✅ Voucher-type validation (pure voucher pairing)
                    $validCombo = (
                        ($payment->linked_invoice_type === 'payment' && $invoice->linked_invoice_type === 'purchase') ||
                        ($payment->linked_invoice_type === 'receipt' && $invoice->linked_invoice_type === 'sale')
                    );

                    if (!$validCombo) continue;

                    // FIFO Application
                    $res = $this->linkRemittanceToParty($payment->fresh(), $invoice->fresh(), $userId);

                    if (!empty($res['success'])) {
                        $summary['matches']++;
                        $summary['gain_loss_total'] += $res['gain_loss'] ?? 0;
                    }
                }

                // ⚙️ Carry Forward for remaining advance amount
                $remaining = $payment->fresh()->remaining_base;
                if ($remaining > 0.0001) {
                    $carry = $this->createCarryForward($payment->fresh(), 'unrealised', $userId);
                    if ($carry) $summary['carry_forwards'] += $carry->base_amount;
                }
            }

            return $summary;
        } catch (\Throwable $e) {
            $summary['errors'][] = $e->getMessage();
            return $summary;
        }
    }


    /**
     * Compute unrealised for a remittance using a supplied closing rate
     */
    public function computeUnrealisedForRemittance(ForexRemittance $rem, float $closingRate, ?int $userId = null): array
    {
        $open = $rem->remaining_base;
        if ($open <= 0) return ['success' => false, 'msg' => 'No open balance'];

        $unrealised = round(($closingRate - $rem->exch_rate) * $open, 4);
        $rem->unrealised_gain_loss = $unrealised;
        $rem->closing_rate = $closingRate;
        $rem->save();

        ForexGainLoss::create([
            'remittance_id' => $rem->id,
            'party_type' => $rem->party_type,
            'party_id' => $rem->party_id,
            'currency_id' => $rem->currency_id,
            'transaction_date' => Carbon::now(),
            'base_amount' => $open,
            'amount' => self::convertToLocal($open, $closingRate),
            'book_rate' => $rem->exch_rate,
            'current_rate' => $closingRate,
            'gain_loss_amount' => $unrealised,
            'type' => 'unrealised',
            'status' => 'approved',
            'created_by' => $userId,
        ]);

        return ['success' => true, 'gain_loss' => $unrealised];
    }

    /**
     * Full recalculation across all parties (used by a CLI or admin reconcile)
     */
    public function recalculateAll(?int $partyId = null, ?string $partyType = null, ?int $userId = null): array
    {
        $set = ForexRemittance::select('party_id', 'party_type')->distinct();
        if ($partyId) $set->where('party_id', $partyId);
        if ($partyType) $set->where('party_type', $partyType);

        $parties = $set->get();
        $report = [];

        foreach ($parties as $p) {
            $report[] = [
                'party_id' => $p->party_id,
                'party_type' => $p->party_type,
                'result' => $this->autoMatchRemittancesForParty($p->party_id, $userId)
            ];
        }

        return $report;
    }
}
