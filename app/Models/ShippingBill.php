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

        'ddb',
        'rodtep',

        'status',
        'created_by',

        'taxable_amount',
        'net_amount',

        'ddb_date',
        'rodtep_date',
        'status_date',

        'ddb_status',
        'rodtep_status',



    ];

    protected $casts = [
        'invoice_date'        => 'date',
        'shipping_bill_date'  => 'date',

        'usd_invoice_amount'  => 'float',
        'fob_value'           => 'float',
        'freight'             => 'float',
        'insurance'           => 'float',

        'igst_value'          => 'float',

        'ddb'                 => 'float',
        'rodtep'              => 'float',

        'taxable_amount' => 'float',
        'net_amount'     => 'float',

        'ddb_date'     => 'date',
        'rodtep_date'  => 'date',
        'status_date'  => 'date',


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
