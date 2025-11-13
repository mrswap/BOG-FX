<?php 
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexAdjustment extends Model
{
    protected $fillable = [
        'party_id','invoice_id','payment_id','adjusted_base_amount','adjusted_local_amount','realised_gain_loss'
    ];

    public function invoice() { return $this->belongsTo(ForexRemittance::class, 'invoice_id'); }
    public function payment() { return $this->belongsTo(ForexRemittance::class, 'payment_id'); }
    public function party() { return $this->belongsTo(Party::class); }
}
