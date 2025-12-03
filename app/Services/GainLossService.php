<?php

namespace App\Services;

class GainLossService
{
    /**
     * Realised for sale: matched_base * (settlement_rate - invoice_rate)
     * Realised for purchase: matched_base * (invoice_rate - settlement_rate)
     *
     * @param float $matchedBase
     * @param float $invoiceRate
     * @param float $settlementRate
     * @param string $side  'sale'|'purchase'
     * @return float realised local amount (positive = gain, negative = loss)
     */
    public function calcRealised(float $matchedBase, float $invoiceRate, float $settlementRate, string $side): float
    {
        if ($side === 'sale') {
            return $matchedBase * ($settlementRate - $invoiceRate);
        }

        // purchase
        return $matchedBase * ($invoiceRate - $settlementRate);
    }

    /**
     * Unrealised for invoice portion: remaining_base * (closing_rate - invoice_rate)
     * For advance (remaining settlement portion): remaining_base * (settlement_rate - closing_rate)
     *
     * @param float $remainingBase
     * @param float|null $closingRate
     * @param float $invoiceRate
     * @param string $type 'invoice'|'advance'
     * @param float|null $settlementRate optional for advance
     * @return float
     */
    public function calcUnrealised(float $remainingBase, ?float $closingRate, float $invoiceRate, string $type = 'invoice', ?float $settlementRate = null): float
    {
        if (is_null($closingRate)) {
            // fallback: return 0 (per rules) or use avg_rate depending on config
            return 0.0;
        }

        if ($type === 'invoice') {
            return $remainingBase * ($closingRate - $invoiceRate);
        }

        // advance
        return $remainingBase * ($settlementRate - $closingRate);
    }
}
