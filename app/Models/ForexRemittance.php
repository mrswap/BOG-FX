<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ForexRemittance extends Model
{
    protected $fillable = [
        'party_id',
        'party_type',
        'voucher_type',
        'voucher_no',
        'transaction_date',
        'base_currency_id',
        'local_currency_id',
        'base_amount',
        'exchange_rate',
        'local_amount',
        'avg_rate',
        'closing_rate',
        'settled_base_amount',
        'remaining_base_amount',
        'realised_gain',
        'realised_loss',
        'unrealised_gain',
        'unrealised_loss',
        'remarks'
    ];

    protected $casts = [
        'transaction_date' => 'date:Y-m-d',
        'base_amount' => 'decimal:4',
        'local_amount' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
        'avg_rate' => 'decimal:6',
        'closing_rate' => 'decimal:6',
        'settled_base_amount' => 'decimal:4',
        'remaining_base_amount' => 'decimal:4',
    ];

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function localCurrency()
    {
        return $this->belongsTo(Currency::class, 'local_currency_id');
    }

    public function matchesAsInvoice()
    {
        return $this->hasMany(ForexMatch::class, 'invoice_id');
    }

    public function matchesAsSettlement()
    {
        return $this->hasMany(ForexMatch::class, 'settlement_id');
    }

    public function getMatchesAttribute()
    {
        $invoiceMatches = $this->relationLoaded('matchesAsInvoice')
            ? $this->matchesAsInvoice
            : $this->matchesAsInvoice()->get();

        $settlementMatches = $this->relationLoaded('matchesAsSettlement')
            ? $this->matchesAsSettlement
            : $this->matchesAsSettlement()->get();

        return $invoiceMatches->merge($settlementMatches)->values();
    }
}
