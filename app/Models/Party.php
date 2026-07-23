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
        'user_id',
        'is_active',
        'image'
    ];

    public function forexRemittances()
    {
        return $this->hasMany(ForexRemittance::class, 'party_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}