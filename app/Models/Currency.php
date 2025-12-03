<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Currency
 *
 * Columns:
 *  - id
 *  - name
 *  - code
 *  - exchange_rate
 *  - is_active
 *  - created_at
 *  - updated_at
 */
class Currency extends Model
{
    // If your table name differs, set protected $table = 'currencies';
    // protected $table = 'currencies';

    protected $fillable = [
        'name',
        'code',
        'exchange_rate',
        'is_active',
    ];

    protected $casts = [
        'exchange_rate' => 'float',
        'is_active' => 'boolean',
    ];

    /**
     * Scope to fetch only active currencies.
     *
     * Usage: Currency::active()->get();
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    /**
     * Helper: returns the display label like "USD — US Dollar".
     */
    public function getLabelAttribute()
    {
        return "{$this->code} — {$this->name}";
    }

    /**
     * Optionally: get current rate (alias).
     */
    public function getRateAttribute()
    {
        return $this->exchange_rate;
    }
}
