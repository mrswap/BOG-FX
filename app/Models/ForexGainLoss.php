<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ForexGainLoss extends Model
{
    use HasFactory;

    protected $fillable = [
        'remittance_id',
        'invoice_id',
        'invoice_type',
        'party_type',
        'party_id',
        'currency_id',
        'transaction_date',
        'amount',
        'base_amount',
        'book_rate',
        'current_rate',
        'gain_loss_amount',
        'type',
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

    // 🔗 Relationships
    public function remittance()
    {
        return $this->belongsTo(ForexRemittance::class, 'remittance_id');
    }

    public function party()
    {
        return $this->morphTo(null, 'party_type', 'party_id');
    }

    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }
}
