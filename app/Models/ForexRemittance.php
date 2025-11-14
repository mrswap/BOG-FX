<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexRemittance extends Model
{
    protected $fillable = [
        'party_id',
        'party_type',
        'transaction_date',
        'voucher_type',
        'voucher_no',
        'base_currency_id',
        'local_currency_id',
        'base_amount',
        'exchange_rate',
        'local_amount',
        'avg_rate',
        'closing_rate',
        'diff',
        'gain_loss_type',
        'gain_loss_value',
        'remarks'
    ];

    protected $casts = [
        'transaction_date' => 'date',
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
    public function adjustments()
    {
        return $this->hasMany(ForexAdjustment::class, 'invoice_id');
    }

    public function adjustmentsAsInvoice()
    {
        return $this->hasMany(ForexAdjustment::class, 'invoice_id');
    }

    public function adjustmentsAsPayment()
    {
        return $this->hasMany(ForexAdjustment::class, 'payment_id');
    }
}
