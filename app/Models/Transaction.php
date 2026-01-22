<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'transactions';

    protected $fillable = [
        'party_id','party_type','transaction_date','base_currency_id','base_amount',
        'closing_rate','local_currency_id','exchange_rate','local_amount',
        'voucher_type','voucher_no','remarks','attachment'
    ];

    protected $casts = [
        'base_amount' => 'float',
        'exchange_rate' => 'float',
        'local_amount' => 'float',
        'closing_rate' => 'float',
        'transaction_date' => 'date',
    ];

    // PARTY relation — must exist
    public function party()
    {
        return $this->belongsTo(\App\Models\Party::class, 'party_id');
    }

    public function baseCurrency()
    {
        return $this->belongsTo(\App\Models\Currency::class, 'base_currency_id');
    }

    public function localCurrency()
    {
        return $this->belongsTo(\App\Models\Currency::class, 'local_currency_id');
    }

    public function matchesAsInvoice()
    {
        return $this->hasMany(\App\Models\ForexMatch::class, 'invoice_id', 'id');
    }

    public function matchesAsSettlement()
    {
        return $this->hasMany(\App\Models\ForexMatch::class, 'settlement_id', 'id');
    }

    public function isInvoice(): bool
    {
        return in_array($this->voucher_type, ['sale','purchase']);
    }

    public function isSettlement(): bool
    {
        return in_array($this->voucher_type, ['receipt','payment']);
    }
}
