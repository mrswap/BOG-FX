<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ForexRate
 *
 * Stores closing/market rates (company-level) for currency pairs by date.
 *
 * Columns:
 *  - id
 *  - date
 *  - base_currency_code
 *  - local_currency_code
 *  - rate
 *  - created_at
 *  - updated_at
 */
class ForexRate extends Model
{
    protected $table = 'forex_rates';

    protected $fillable = [
        'date',
        'base_currency_code',
        'local_currency_code',
        'rate',
    ];

    protected $casts = [
        'date' => 'date',
        'rate' => 'float',
    ];

    /**
     * Scope: specific pair
     */
    public function scopeForPair($query, string $baseCode, string $localCode)
    {
        return $query->where('base_currency_code', $baseCode)
                     ->where('local_currency_code', $localCode);
    }

    /**
     * Get latest closing rate for a given date (<= $date).
     *
     * @param string $baseCode
     * @param string $localCode
     * @param \DateTime|string $date
     * @return float|null
     */
    public static function getClosingRate(string $baseCode, string $localCode, $date): ?float
    {
        $r = self::where('base_currency_code', $baseCode)
            ->where('local_currency_code', $localCode)
            ->where('date', '<=', $date)
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $r ? (float) $r->rate : null;
    }

    /**
     * Convenience: insert or update today's rate for a pair.
     *
     * @param string $baseCode
     * @param string $localCode
     * @param \DateTime|string $date
     * @param float $rate
     * @return ForexRate
     */
    public static function upsertRate(string $baseCode, string $localCode, $date, float $rate): self
    {
        $attributes = [
            'date' => $date,
            'base_currency_code' => $baseCode,
            'local_currency_code' => $localCode,
        ];

        $values = ['rate' => $rate];

        $model = self::updateOrCreate($attributes, $values);

        return $model;
    }
}
