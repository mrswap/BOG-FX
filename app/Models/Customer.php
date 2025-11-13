<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        "customer_group_id",
        "user_id",
        "name",
        "company_name",
        "email",
        "phone_number",
        "tax_no",
        "address",
        "city",
        "state",
        "postal_code",
        "country",
        "points",
        "deposit",
        "expense",
        "wishlist",
        "is_active",
        "base_currency_id"
    ];

    public function customerGroup()
    {
        return $this->belongsTo('App\Models\CustomerGroup');
    }

    public function user()
    {
        return $this->belongsTo('App\Models\User');
    }

    public function discountPlans()
    {
        return $this->belongsToMany('App\Models\DiscountPlan', 'discount_plan_customers');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function forexRemittances()
    {
        return $this->hasMany(ForexRemittance::class, 'party_id')
            ->where('party_type', 'customer');
    }
    public function partyPayments()
    {
        return $this->hasMany(PartyPayment::class, 'party_id')->where('party_type', 'customer');
    }
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'party_id')->where('party_type', 'customer');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'party_id')->where('party_type', 'supplier');
    }


    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }
}
