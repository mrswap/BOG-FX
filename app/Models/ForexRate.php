<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexRate extends Model
{
    protected $fillable = [
        'currency_id',
        'rate_date',
        'closing_rate',
        'notes',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'closing_rate' => 'decimal:6'
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function scopeByDate($q, $date)
    {
        return $q->where('rate_date', $date);
    }

    public function scopeLatestRate($q, $currencyId)
    {
        return $q->where('currency_id', $currencyId)
            ->orderBy('rate_date', 'desc')
            ->first();
    }
}
