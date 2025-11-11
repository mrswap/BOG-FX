<?php
namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexGainLoss;
use App\Models\Sale;
use App\Models\Purchase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ForexService
{
    /**
     * Convert foreign amount to local/base amount
     */
    public static function convertToLocal(float $foreignAmount, float $rate): float
    {
        return round($foreignAmount * $rate, 4);
    }

    /**
     * Realised gain/loss: (paymentRate - invoiceRate) * usdSettled
     * Positive => Gain, Negative => Loss
     */
    public static function realisedGainLoss(float $usdSettled, float $paymentRate, float $invoiceRate): float
    {
        return round(($paymentRate - $invoiceRate) * $usdSettled, 2);
    }

    /**
     * Unrealised gain/loss: (closingRate - txnRate) * usdOpen
     */
    public static function unrealisedGainLoss(float $usdOpen, float $txnRate, float $closingRate): float
    {
        return round(($closingRate - $txnRate) * $usdOpen, 2);
    }

    /**
     * Link a remittance to an invoice (sale or purchase).
     * Accepts the remittance and invoice meta; applies a foreign amount (USD) from remittance to invoice.
     * Returns array with realised, unrealised (if any), and applied amounts in foreign/local.
     *
     * - $invoiceType: 'sale' or 'purchase'
     * - $applyForeignAmount: amount in foreign currency to apply from remittance to invoice
     *
     * Note: assumes invoices (Sale/Purchase) have currency_id and exchange_rate stored.
     */
    public static function linkRemittanceToInvoice(ForexRemittance $rem, string $invoiceType, int $invoiceId, float $applyForeignAmount, int $userId = null)
    {
        DB::beginTransaction();
        try {
            if ($invoiceType === 'sale') {
                $invoice = Sale::findOrFail($invoiceId);
            } else {
                $invoice = Purchase::findOrFail($invoiceId);
            }

            // invoice's USD amount (if invoice created in USD) - but invoice could be local currency;
            // We'll calculate based on currency alignment. We'll assume invoice currency is the same as rem.currency_id or that invoice.exchange_rate exists.
            $invoiceRate = $invoice->exchange_rate ?: 0; // invoice rate to convert foreign→local
            $paymentRate = $rem->exch_rate;

            // USD settled equals applyForeignAmount
            $usdSettled = $applyForeignAmount;

            // computed local applied amount using the remittance's payment rate
            $appliedLocal = self::convertToLocal($usdSettled, $paymentRate);

            // Realised gain/loss computed as (paymentRate - invoiceRate) * USD_settled
            $realised = self::realisedGainLoss($usdSettled, $paymentRate, $invoiceRate);

            // Update remittance: subtract applied foreign from usd_amount (remaining becomes unrealised)
            $rem->usd_amount = round(max(0, $rem->usd_amount - $usdSettled), 4);
            $rem->local_amount = round(max(0, $rem->local_amount - $appliedLocal), 4);

            // If remittance fully consumed, set type/status accordingly (we keep type field as receipt/payment)
            $status = ($rem->usd_amount <= 0) ? 'realised' : 'partial';
            $rem->save();

            // Create gain/loss ledger entry
            $gl = ForexGainLoss::create([
                'remittance_id' => $rem->id,
                'party_type' => $rem->party_type,
                'party_id' => $rem->party_id,
                'currency_id' => $rem->currency_id,
                'transaction_date' => Carbon::now()->toDateString(),
                'usd_amount' => $usdSettled,
                'local_amount' => $appliedLocal,
                'book_rate' => $invoiceRate,
                'current_rate' => $paymentRate,
                'gain_loss_amount' => $realised,
                'type' => 'realised',
                'status' => 'realised',
                'created_by' => $userId,
            ]);

            // Optionally: create a link row in 'forex_payment_links' table if you added it
            // DB::table('forex_payment_links')->insert([...]);

            DB::commit();

            return [
                'success' => true,
                'realised' => $realised,
                'applied_local' => $appliedLocal,
                'remaining_usd' => $rem->usd_amount,
                'remaining_local' => $rem->local_amount,
                'gain_loss_id' => $gl->id,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
