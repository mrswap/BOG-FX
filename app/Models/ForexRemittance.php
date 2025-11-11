<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexRemittance extends Model
{
    protected $fillable = [
        'party_type',
        'party_id',
        'currency_id',
        'reference_no',
        'transaction_date',
        'exch_rate',
        'usd_amount',
        'local_amount',
        'remarks',
        'type',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'usd_amount' => 'decimal:4',
        'local_amount' => 'decimal:4',
        'exch_rate' => 'decimal:6',
    ];

    // 🔗 Relationships
    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'party_id')
                    ->where('party_type', 'customer');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'party_id')
                    ->where('party_type', 'supplier');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
