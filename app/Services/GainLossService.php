<?php

namespace App\Services;

class GainLossService
{
    /**
     * Realised for sale: matched_base * (settlement_rate - invoice_rate)
     * Realised for purchase: matched_base * (invoice_rate - settlement_rate)
     *
     * Positive = gain, Negative = loss (local currency units)
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
     * Unrealised:
     * - invoice: remainingBase * (closingRate - invoiceRate)
     * - advance: remainingBase * (settlementRate - closingRate)
     *
     * Special override:
     *    If invoiceRateOverride provided (business special case), apply:
     *    unrealised = remainingBase * (invoiceRateOverride - settlementRate)
     *
     * @param float $remainingBase
     * @param float|null $closingRate
     * @param float $invoiceRate
     * @param string $type 'invoice'|'advance'
     * @param float|null $settlementRate
     * @param float|null $invoiceRateOverride
     * @return float
     */
    public function calcUnrealised(
        float $remainingBase,
        ?float $closingRate,
        float $invoiceRate,
        string $type = 'invoice',
        ?float $settlementRate = null,
        ?float $invoiceRateOverride = null
    ): float {
        // Special-case override (your -450 example)
        if (!is_null($invoiceRateOverride) && $type === 'advance' && !is_null($settlementRate)) {
            return $remainingBase * ($invoiceRateOverride - $settlementRate);
        }

        // If closingRate missing -> 0 (should not happen after resolver)
        if (is_null($closingRate)) {
            return 0.0;
        }

        if ($type === 'invoice') {
            return $remainingBase * ($closingRate - $invoiceRate);
        }

        // advance
        if (is_null($settlementRate)) {
            return 0.0;
        }

        return $remainingBase * ($settlementRate - $closingRate);
    }
}
