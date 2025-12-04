<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexRate extends Model
{
    protected $table = 'forex_rates';
    public $timestamps = false;

    protected $fillable = [
        'date',
        'base_currency_id',
        'local_currency_id',
        'party_id',      // optional - used for party-wise auto rates
        'rate',
    ];

    /**
     * Exact closing rate for date.
     */
    public static function getClosingRate(int $baseId, int $localId, string $date)
    {
        return self::where('base_currency_id', $baseId)
            ->where('local_currency_id', $localId)
            ->where('date', $date)
            ->value('rate');
    }

    /**
     * Latest before given date.
     */
    public static function getLatestBefore(int $baseId, int $localId, string $date)
    {
        return self::where('base_currency_id', $baseId)
            ->where('local_currency_id', $localId)
            ->where('date', '<=', $date)
            ->orderBy('date', 'desc')
            ->value('rate');
    }

    /**
     * Latest available rate.
     */
    public static function getLatestRate(int $baseId, int $localId)
    {
        return self::where('base_currency_id', $baseId)
            ->where('local_currency_id', $localId)
            ->orderBy('date', 'desc')
            ->value('rate');
    }
}
