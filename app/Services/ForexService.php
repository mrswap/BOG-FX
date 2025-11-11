<?php

namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\PartyPayment;
use App\Models\ForexGainLoss;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ForexService
{
    /**
     * Convert base (USD) to local currency amount
     */
    public static function convertToLocal(float $baseAmount, float $rate): float
    {
        return round($baseAmount * $rate, 4);
    }

    /**
     * Calculate realised gain/loss = (paymentRate - remittanceRate) * appliedBase
     */
    public static function realisedGainLoss(float $baseAmount, float $paymentRate, float $remittanceRate): float
    {
        return round(($paymentRate - $remittanceRate) * $baseAmount, 2);
    }

    /**
     * Apply payment to a remittance and record realised gain/loss
     */
    public static function applyPaymentToRemittance(PartyPayment $payment, ?ForexRemittance $rem = null, ?int $userId = null)
    {
        DB::beginTransaction();
        try {
            // Step 1: Auto-match remittance if not passed
            if (!$rem) {
                $rem = ForexRemittance::where('party_type', $payment->party_type)
                    ->where('party_id', $payment->party_id)
                    ->where('voucher_no', $payment->payment_reference)
                    ->first();
            }

            if (!$rem) {
                throw new \Exception("No remittance found for voucher {$payment->payment_reference}");
            }

            // Step 2: Determine how much base (USD) is being applied
            $appliedBase = min($payment->base_amount, $rem->base_amount);

            $paymentRate = (float)$payment->exchange_rate;
            $remittanceRate = (float)$rem->exchange_rate;

            $appliedAmount = self::convertToLocal($appliedBase, $paymentRate);
            $realisedGainLoss = self::realisedGainLoss($appliedBase, $paymentRate, $remittanceRate);

            // Step 3: Update remittance balance
            $rem->base_amount = round($rem->base_amount - $appliedBase, 4);
            $rem->amount = round($rem->amount - self::convertToLocal($appliedBase, $remittanceRate), 4);
            $rem->realised_gain_loss += $realisedGainLoss;
            $rem->status = ($rem->base_amount <= 0) ? 'realised' : 'partial';
            $rem->save();

            // Step 4: Record gain/loss entry
            ForexGainLoss::create([
                'remittance_id'    => $rem->id,
                'party_type'       => $rem->party_type,
                'party_id'         => $rem->party_id,
                'currency_id'      => $rem->currency_id,
                'transaction_date' => Carbon::now()->toDateString(),
                'base_amount'      => $appliedBase,
                'amount'           => $appliedAmount,
                'book_rate'        => $remittanceRate,
                'current_rate'     => $paymentRate,
                'gain_loss_amount' => $realisedGainLoss,
                'type'             => 'realised',
                'status'           => 'realised',
                'created_by'       => $userId,
            ]);

            // Step 5: Link payment to remittance
            $payment->related_invoice_type = 'remittance';
            $payment->related_invoice_id = $rem->id;
            $payment->save();

            DB::commit();

            return [
                'success'          => true,
                'message'          => 'Payment applied successfully.',
                'applied_base'     => $appliedBase,
                'applied_amount'   => $appliedAmount,
                'realised_gain'    => $realisedGainLoss,
                'remaining_base'   => $rem->base_amount,
                'remaining_amount' => $rem->amount,
                'remittance_id'    => $rem->id,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Compute unrealised gain/loss for open remittances
     */
    public static function computeUnrealised(ForexRemittance $rem, float $closingRate, ?int $userId = null)
    {
        $baseOpen = $rem->base_amount;
        $unrealised = round(($closingRate - $rem->exchange_rate) * $baseOpen, 2);

        $rem->unrealised_gain_loss = $unrealised;
        $rem->closing_rate = $closingRate;
        $rem->save();

        ForexGainLoss::create([
            'remittance_id'    => $rem->id,
            'party_type'       => $rem->party_type,
            'party_id'         => $rem->party_id,
            'currency_id'      => $rem->currency_id,
            'transaction_date' => Carbon::now()->toDateString(),
            'base_amount'      => $baseOpen,
            'amount'           => self::convertToLocal($baseOpen, $closingRate),
            'book_rate'        => $rem->exchange_rate,
            'current_rate'     => $closingRate,
            'gain_loss_amount' => $unrealised,
            'type'             => 'unrealised',
            'status'           => 'open',
            'created_by'       => $userId,
        ]);

        return [
            'success'       => true,
            'unrealised'    => $unrealised,
            'closing_rate'  => $closingRate,
        ];
    }

    public static function applyInvoiceToRemittance(ForexRemittance $rem, float $invoiceBase, float $invoiceRate, ?int $userId = null)
    {
        $remittanceRate = (float)$rem->exch_rate;
        $gainLoss = round(($remittanceRate - $invoiceRate) * $invoiceBase, 2);

        // Reduce remittance open amount
        $rem->base_amount -= $invoiceBase;
        $rem->local_amount -= round($invoiceBase * $remittanceRate, 2);
        $rem->realised_gain_loss += $gainLoss;
        $rem->status = $rem->base_amount <= 0 ? 'realised' : 'partial';
        $rem->save();

        // Record in forex_gain_losses
        ForexGainLoss::create([
            'remittance_id' => $rem->id,
            'party_type' => $rem->party_type,
            'party_id' => $rem->party_id,
            'currency_id' => $rem->currency_id,
            'transaction_date' => now()->toDateString(),
            'base_amount' => $invoiceBase,
            'amount' => round($invoiceBase * $invoiceRate, 2),
            'book_rate' => $remittanceRate,
            'current_rate' => $invoiceRate,
            'gain_loss_amount' => $gainLoss,
            'type' => 'realised',
            'status' => 'realised',
            'created_by' => $userId,
        ]);

        return [
            'success' => true,
            'applied_base' => $invoiceBase,
            'realised_gain_loss' => $gainLoss,
            'remaining_base' => $rem->base_amount,
            'remaining_local' => $rem->local_amount,
        ];
    }
    public function initializeRemittance(ForexRemittance $remittance)
    {
        // Create initial unrealised gain/loss entry
        return $this->createGainLossEntry($remittance, 'unrealised');
    }
    
    /**
     * Link a remittance to a party reference manually (direct linkage)
     */


    public static function linkRemittanceToParty($remittance, $invoiceId, $invoiceType, $invoiceBaseAmount, $invoiceExchangeRate, $userId)
    {
        $remittanceRate = $remittance->exch_rate;
        $remainingBase  = $remittance->base_amount - $remittance->applied_base;
        $appliedBase    = min($invoiceBaseAmount, $remainingBase);

        $remittanceLocal = $appliedBase * $remittanceRate;
        $invoiceLocal    = $appliedBase * $invoiceExchangeRate;

        $realisedGainLoss = round($invoiceLocal - $remittanceLocal, 4);

        // 🔖 Record realised gain/loss
        $gainLoss = ForexGainLoss::create([
            'remittance_id'  => $remittance->id,
            'invoice_id'     => $invoiceId,
            'invoice_type'   => $invoiceType,
            'applied_base'   => $appliedBase,
            'remittance_rate' => $remittanceRate,
            'invoice_rate'   => $invoiceExchangeRate,
            'realised_gain_loss' => $realisedGainLoss,
            'created_by'     => $userId,
        ]);

        // Update remittance remaining
        $remittance->update([
            'applied_base'   => $remittance->applied_base + $appliedBase,
            'remaining_base' => $remainingBase - $appliedBase,
        ]);

        return [
            'success' => true,
            'applied_base' => $appliedBase,
            'realised_gain_loss' => $realisedGainLoss,
            'remaining_base' => max(0, $remainingBase - $appliedBase),
            'gain_loss_id' => $gainLoss->id,
        ];
    }
}
