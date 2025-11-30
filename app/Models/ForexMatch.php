<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexMatch extends Model
{
    protected $fillable = [
        'invoice_id',
        'settlement_id',
        'match_date',
        'matched_base_amount',
        'matched_local_amount',
        'invoice_rate',
        'settlement_rate',
        'realised_gain_loss'
    ];

    protected $casts = [
        'match_date' => 'date',
        'matched_base_amount' => 'decimal:4',
        'matched_local_amount' => 'decimal:4',
        'invoice_rate' => 'decimal:6',
        'settlement_rate' => 'decimal:6',
        'realised_gain_loss' => 'decimal:4',
    ];

    public function invoice()
    {
        return $this->belongsTo(ForexRemittance::class, 'invoice_id');
    }

    public function settlement()
    {
        return $this->belongsTo(ForexRemittance::class, 'settlement_id');
    }
}
