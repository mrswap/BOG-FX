<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $fillable = ["name", "code", "exchange_rate", "is_active"];

    public function forexRemittances()
    {
        return $this->hasMany(ForexRemittance::class);
    }

    public function forexRates()
    {
        return $this->hasMany(ForexRate::class);
    }

    // ✅ Add this scope
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
