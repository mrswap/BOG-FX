<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PartyPayment extends Model
{
    use HasFactory;

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

    // 🔗 Relations
    public function party()
    {
        return $this->morphTo(null, 'party_type', 'party_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function remittance()
    {
        return $this->belongsTo(ForexRemittance::class, 'payment_reference', 'voucher_no');
    }

    public function relatedInvoice()
    {
        if ($this->related_invoice_type === 'sale') {
            return $this->belongsTo(Sale::class, 'related_invoice_id');
        }
        if ($this->related_invoice_type === 'purchase') {
            return $this->belongsTo(Purchase::class, 'related_invoice_id');
        }
        return null;
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
