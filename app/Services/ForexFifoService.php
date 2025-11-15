<?php

namespace App\Services;

use App\Models\ForexRemittance;
use App\Models\ForexChunk;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class ForexFifoService
{
    /**
     * Map voucher type to FIFO direction (admin/system perspective)
     * purchase => debit  (admin will pay)
     * sale     => credit (admin will receive)
     * payment  => credit (admin paid -> party received)
     * receipt  => debit  (admin received -> party paid)
     */
    protected function voucherDirection(string $vchType): string
    {
        $t = strtolower($vchType);

        return match ($t) {
            'payment'  => 'debit',   // Admin → party को पैसा दिया → cash outflow
            'receipt'  => 'debit',   // Party → admin को पैसा देता है → cash inflow
            'purchase' => 'credit',  // Admin ने USD में माल खरीदा → payable
            'sale'     => 'credit',  // Admin ने USD में माल बेचा → receivable
            default    => 'debit',   // Default safe side
        };
    }
    /**
     * Process a single remittance: create chunk + attempt FIFO match
     */
    public function processRemittance(ForexRemittance $rem)
    {
        DB::beginTransaction();
        try {
            $partyId = $rem->party_id;
            $dir = $this->voucherDirection($rem->voucher_type);
            $currencyId = $rem->base_currency_id;
            $txnDate = $rem->transaction_date ? Carbon::parse($rem->transaction_date)->toDateString() : Carbon::now()->toDateString();
            $baseAmount = (float)$rem->base_amount;
            $rate = (float)$rem->exchange_rate;
            $localAmount = round($baseAmount * $rate, 4);

            // create chunk for remittance
            $chunk = ForexChunk::create([
                'party_id' => $partyId,
                'remittance_id' => $rem->id,
                'voucher_type' => strtolower($rem->voucher_type),
                'direction' => $dir,
                'base_currency_id' => $currencyId,
                'base_amount_orig' => $baseAmount,
                'base_amount_remaining' => $baseAmount,
                'exchange_rate' => $rate,
                'local_amount_orig' => $localAmount,
                'transaction_date' => $txnDate,
                'is_settled' => false,
            ]);

            // attempt FIFO match
            $this->matchChunkFIFO($chunk);

            // refresh remittance status (gain_loss_type / value)
            $this->refreshRemittanceStatus($rem);

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * FIFO matching engine between a created chunk and opposite chunks.
     */
    protected function matchChunkFIFO(ForexChunk $chunk)
    {
        $partyId = $chunk->party_id;
        $currencyId = $chunk->base_currency_id;
        $dir = $chunk->direction;
        $remaining = (float)$chunk->base_amount_remaining;
        if ($remaining <= 0) return;

        $oppDir = $dir === 'debit' ? 'credit' : 'debit';

        $oppositeChunks = ForexChunk::where('party_id', $partyId)
            ->where('base_currency_id', $currencyId)
            ->where('direction', $oppDir)
            ->where('base_amount_remaining', '>', 0)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($oppositeChunks as $opp) {
            if ($remaining <= 0) break;
            $oppRemaining = (float)$opp->base_amount_remaining;
            if ($oppRemaining <= 0) continue;

            $adjust = min($remaining, $oppRemaining);

            // Determine invoice vs payment for formula:
            // Invoice = debit (purchase) or credit (sale) depending voucher_type from chunk data
            // For calculation we treat the "invoice" as the side that originally created invoice (purchase or sale)
            // We adopt invoice-centric formula:
            // purchase: gain = (invoice_rate - payment_rate) * adjusted
            // sale:     gain = (payment_rate - invoice_rate) * adjusted

            // decide which chunk is invoice and which is payment
            $debitChunk = $chunk->direction === 'debit' ? $chunk : $opp;
            $creditChunk = $chunk->direction === 'credit' ? $chunk : $opp;

            $invoiceRemId = $debitChunk->remittance_id;
            $paymentRemId = $creditChunk->remittance_id;

            $invoiceRate = (float)$debitChunk->exchange_rate;
            $paymentRate = (float)$creditChunk->exchange_rate;

            // if invoice voucher_type is 'purchase' then invoice-centric purchase formula
            $invoiceRem = ForexRemittance::find($invoiceRemId);
            $gain = 0.0;
            if ($invoiceRem && strtolower($invoiceRem->voucher_type) === 'purchase') {
                $gain = ($invoiceRate - $paymentRate) * $adjust;
            } else {
                // sale or other -> sale formula
                $gain = ($paymentRate - $invoiceRate) * $adjust;
            }

            $adjustedLocal = round($adjust * $invoiceRate, 4);

            DB::table('forex_adjustments')->insert([
                'party_id' => $partyId,
                'invoice_id' => $invoiceRemId,
                'payment_id' => $paymentRemId,
                'adjusted_base_amount' => round($adjust, 6),
                'adjusted_local_amount' => $adjustedLocal,
                'realised_gain_loss' => round($gain, 6),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // decrement
            $remaining -= $adjust;
            $oppRemaining -= $adjust;

            $chunk->base_amount_remaining = round($remaining, 6);
            $chunk->save();

            $opp->base_amount_remaining = round($oppRemaining, 6);
            $opp->save();

            if (bccomp((string)$chunk->base_amount_remaining, '0', 6) === 0) {
                $chunk->is_settled = true;
                $chunk->save();
            }
            if (bccomp((string)$opp->base_amount_remaining, '0', 6) === 0) {
                $opp->is_settled = true;
                $opp->save();
            }
        }
    }

    /**
     * Recalculate and update remittance's gain_loss_type and gain_loss_value.
     * Mark realised/unrealised type depending if base_amount_remaining = 0.
     */
    public function refreshRemittanceStatus(ForexRemittance $rem)
    {
        $sumAdjAsInvoice = (float) DB::table('forex_adjustments')->where('invoice_id', $rem->id)->sum('adjusted_base_amount');
        $sumAdjAsPayment = (float) DB::table('forex_adjustments')->where('payment_id', $rem->id)->sum('adjusted_base_amount');

        $isInvoice = in_array(strtolower($rem->voucher_type), ['purchase', 'sale']);
        $adjusted = $isInvoice ? $sumAdjAsInvoice : $sumAdjAsPayment;
        $open = max(0.0, (float)$rem->base_amount - $adjusted);

        $realisedSum = (float) DB::table('forex_adjustments')
            ->where(function ($q) use ($rem) {
                $q->where('invoice_id', $rem->id)
                    ->orWhere('payment_id', $rem->id);
            })
            ->sum('realised_gain_loss');

        if ($open <= 0.000001) {
            $rem->gain_loss_type = 'realised';
            $rem->gain_loss_value = round($realisedSum, 6);
        } else {
            $rem->gain_loss_type = 'unrealised';
            $rem->gain_loss_value = round($realisedSum, 6);
        }
        $rem->save();
    }

    /**
     * Compute open/unrealised for remittance using the remaining chunk and closing rate.
     * Positive returned = gain, negative = loss.
     */
    public function computeOpenUnrealised(ForexRemittance $rem, ?float $closingRate = null): float
    {
        $chunk = ForexChunk::where('remittance_id', $rem->id)->first();
        if (!$chunk) return 0.0;

        $open = (float)$chunk->base_amount_remaining;
        if ($open <= 0) return 0.0;

        $closing = $rem->closing_rate ?? $closingRate ?? null;
        if (is_null($closing)) return 0.0;

        $rate = (float)$chunk->exchange_rate;
        // For direction: debit open (admin to pay) => unrealised = (rate - closing)*open (per your earlier code)
        if ($chunk->direction === 'debit') {
            $val = ($rate - $closing) * $open;
        } else {
            // credit open (advance) => unrealised = (closing - rate) * open ? maintain consistent sign
            $val = ($closing - $rate) * $open;
        }
        return round($val, 6);
    }

    /**
     * Compute full revaluation (legacy)
     */
    public function computeFullRevaluation(ForexRemittance $rem, ?float $closingRate = null): float
    {
        $closing = $rem->closing_rate ?? $closingRate ?? null;
        if (is_null($closing)) return 0.0;
        $rate = (float)$rem->exchange_rate;
        $base = (float)$rem->base_amount;

        if (strtolower($rem->voucher_type) === 'purchase') {
            $val = ($rate - $closing) * $base;
        } elseif (strtolower($rem->voucher_type) === 'sale') {
            $val = ($closing - $rate) * $base;
        } elseif (strtolower($rem->voucher_type) === 'payment') {
            $val = ($rate - $closing) * $base;
        } else {
            $val = ($closing - $rate) * $base;
        }
        return round($val, 6);
    }

    /**
     * Rebuild party queue: delete chunks & adjustments and reprocess remittances in order.
     */
    public function rebuildPartyQueue(int $partyId, ?int $baseCurrencyId = null)
    {
        DB::beginTransaction();
        try {
            $q = ForexRemittance::where('party_id', $partyId)->orderBy('transaction_date', 'asc')->orderBy('id', 'asc');
            if ($baseCurrencyId) $q->where('base_currency_id', $baseCurrencyId);
            $rems = $q->get();

            // delete existing adjustments & chunks for party
            DB::table('forex_adjustments')->where('party_id', $partyId)->delete();
            $chunkQuery = ForexChunk::where('party_id', $partyId);
            if ($baseCurrencyId) $chunkQuery->where('base_currency_id', $baseCurrencyId);
            $chunkQuery->delete();

            // reprocess
            foreach ($rems as $r) {
                $this->processRemittance($r);
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
