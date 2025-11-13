<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ForexRemittance extends Model
{
    use HasFactory;

    protected $fillable = [
        'party_type',
        'party_id',
        'linked_invoice_type',
        'base_currency_id',
        'currency_id',
        'voucher_no',
        'transaction_date',
        'exch_rate',
        'base_amount',
        'local_amount',
        'applied_base',
        'applied_local_amount',
        'realised_gain_loss',
        'unrealised_gain_loss',
        'closing_rate',
        'remarks',
        'status',
        'created_by',
    ];

    protected $dates = ['transaction_date'];
    protected $casts = [
        'transaction_date' => 'datetime',
    ];
    /*
    |--------------------------------------------------------------------------
    | RELATIONSHIPS
    |--------------------------------------------------------------------------
    */

    // Single dynamic relation resolver (customer/supplier based on type)
    public function partyEntity()
    {
        // Abstract accessor — try to fetch from both tables by ID
        return Customer::find($this->party_id) ?? Supplier::find($this->party_id);
    }

    // Just for easier access when type known
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'party_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'party_id');
    }

    // Currencies
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    // Gain/Loss relation
    public function gainLoss()
    {
        return $this->hasMany(ForexGainLoss::class, 'remittance_id');
    }

    // Optional Party Payment
    public function partyPayments()
    {
        return $this->hasMany(PartyPayment::class, 'payment_reference', 'voucher_no');
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESSORS / HELPERS
    |--------------------------------------------------------------------------
    */

    public function getRemainingBaseAttribute()
    {
        return round(($this->base_amount ?? 0) - ($this->applied_base ?? 0), 4);
    }

    public function getIsFullyAppliedAttribute()
    {
        return $this->remaining_base <= 0;
    }

    public function getIsPartialAttribute()
    {
        return $this->applied_base > 0 && $this->remaining_base > 0;
    }

    public function getIsUnappliedAttribute()
    {
        return $this->applied_base <= 0;
    }
}
