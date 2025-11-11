<?php 
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForexAlert extends Model
{
    protected $fillable = [
        'currency_id',
        'threshold_rate',
        'direction',  // above/below
        'is_active',
        'remarks',
        'created_by',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
