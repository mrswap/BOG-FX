<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingBill extends Model
{
    protected $fillable = [
        'transaction_id',
        'export_invoice_no',
        'invoice_date',
        'usd_invoice_amount',

        'shipping_bill_no',
        'shipping_bill_date',
        'port',

        'fob_value',
        'freight',
        'insurance',

        'igst_value',
        'igst_rate',

        'ddb',
        'rodtep',

        'status',
        'created_by'
    ];

    protected $casts = [
        'invoice_date'        => 'date',
        'shipping_bill_date'  => 'date',

        'usd_invoice_amount'  => 'float',
        'fob_value'           => 'float',
        'freight'             => 'float',
        'insurance'           => 'float',

        'igst_value'          => 'float',
        'igst_rate'           => 'float',

        'ddb'                 => 'float',
        'rodtep'              => 'float',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    // ✅ helper for client column
    public function getFreightInsuranceAttribute()
    {
        return ($this->freight ?? 0) + ($this->insurance ?? 0);
    }
}
