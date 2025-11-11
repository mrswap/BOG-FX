<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartyPayment extends Model
{
    protected $fillable = [
        'party_type',
        'party_id',
        'payment_reference',
        'related_invoice_id',
        'related_invoice_type',
        'currency_id',
        'exchange_rate',
        'paid_usd',
        'paid_local',
        'payment_mode',
        'remarks',
        'payment_date',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'paid_usd' => 'decimal:4',
        'paid_local' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
    ];

    // 🔗 Relationships
    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'party_id')->where('party_type', 'customer');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'party_id')->where('party_type', 'supplier');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function relatedInvoice()
    {
        if ($this->related_invoice_type === 'sale') {
            return $this->belongsTo(Sale::class, 'related_invoice_id');
        } elseif ($this->related_invoice_type === 'purchase') {
            return $this->belongsTo(Purchase::class, 'related_invoice_id');
        }
        return null;
    }
}
