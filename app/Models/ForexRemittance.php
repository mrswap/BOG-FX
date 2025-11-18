<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexRemittance extends Model
{
    protected $fillable = [
        'party_id',
        'ledger_type',
        'voucher_type',
        'voucher_no',
        'transaction_date',
        'base_currency_id',
        'local_currency_id',
        'base_amount',
        'exchange_rate',
        'local_amount',
        'avg_rate',
        'closing_rate',
        'direction',
        'settled_base_amount',
        'remaining_base_amount',
        'realised_gain',
        'realised_loss',
        'unrealised_gain',
        'unrealised_loss',
        'remarks'
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'base_amount' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
        'local_amount' => 'decimal:4',
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
}
