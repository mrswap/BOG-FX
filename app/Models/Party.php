<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Party extends Model
{
    protected $fillable = [
        'name',
        'type',
        'company_name',
        'vat_number',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'is_active'
    ];

    public function forexRemittances()
    {
        return $this->hasMany(ForexRemittance::class, 'party_id');
    }
}
