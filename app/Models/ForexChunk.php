<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexChunk extends Model
{
    protected $table = 'forex_chunks';

    protected $fillable = [
        'party_id',
        'remittance_id',
        'voucher_type',
        'direction',
        'base_currency_id',
        'base_amount_orig',
        'base_amount_remaining',
        'exchange_rate',
        'local_amount_orig',
        'transaction_date',
        'is_settled',
    ];

    protected $casts = [
        'base_amount_orig' => 'float',
        'base_amount_remaining' => 'float',
        'exchange_rate' => 'float',
        'local_amount_orig' => 'float',
        'is_settled' => 'boolean',
    ];

    public function remittance()
    {
        return $this->belongsTo(ForexRemittance::class, 'remittance_id');
    }
}
