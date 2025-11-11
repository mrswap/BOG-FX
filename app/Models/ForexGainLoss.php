<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexGainLoss extends Model
{
    protected $fillable = [
        'remittance_id',
        'party_type',
        'party_id',
        'currency_id',
        'transaction_date',
        'amount',          // USD-equivalent amount
        'base_amount',     // local currency amount
        'book_rate',       // invoice rate
        'current_rate',    // remittance rate
        'gain_loss_amount',
        'type',            // realised/unrealised
        'status',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:4',
        'base_amount' => 'decimal:4',
        'book_rate' => 'decimal:6',
        'current_rate' => 'decimal:6',
        'gain_loss_amount' => 'decimal:4',
    ];

    public function remittance()
    {
        return $this->belongsTo(ForexRemittance::class, 'remittance_id');
    }
}
