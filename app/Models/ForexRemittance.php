<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexRemittance extends Model
{
    protected $fillable = [
        'party_type',
        'party_id',
        'base_currency_id',
        'currency_id',
        'voucher_no',             // previously reference_no
        'transaction_date',
        'exch_rate',
        'base_amount',            // amount in base currency
        'local_amount',           // converted amount
        'invoice_amount',         // original invoice amount if exists
        'closing_rate',           // optional, calculated later
        'realised_gain_loss',     // calculated via ForexService
        'unrealised_gain_loss',   // calculated via ForexService
        'applied_local_amount',   // applied local amount to invoice
        'linked_invoice_type',    // 'sale' or 'purchase'
        'linked_invoice_id',      // invoice id
        'remarks',
        'type',                   // receipt/payment
        'status',                 // 'pending', 'partial', 'realised'
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'usd_amount' => 'decimal:4',
        'local_amount' => 'decimal:4',
        'exch_rate' => 'decimal:6',
    ];

    // Relations
    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'party_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'party_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments()
    {
        return $this->hasMany(PartyPayment::class, 'remittance_id');
    }

    public function gainLoss()
    {
        return $this->hasMany(ForexGainLoss::class, 'remittance_id');
    }
}
