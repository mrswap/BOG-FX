<?php 
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexMatch extends Model
{
    protected $fillable = [
        'party_id',
        'ledger_type',
        'base_currency_id',
        'debit_txn_id',
        'credit_txn_id',
        'matched_base_amount',
        'debit_rate',
        'credit_rate',
        'realised_gain',
        'realised_loss',
    ];

    public function debit()
    {
        return $this->belongsTo(ForexRemittance::class, 'debit_txn_id');
    }

    public function credit()
    {
        return $this->belongsTo(ForexRemittance::class, 'credit_txn_id');
    }
}
