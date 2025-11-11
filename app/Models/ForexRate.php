<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexRate extends Model
{
    protected $fillable = [
        'currency_id',
        'date',
        'buy_rate',
        'sell_rate',
        'source',
    ];

    protected $casts = [
        'date' => 'date',
        'buy_rate' => 'decimal:6',
        'sell_rate' => 'decimal:6',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
