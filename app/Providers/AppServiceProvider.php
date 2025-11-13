<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {


        // Schema::defaultStringLength(191);
        $this->app->bind(\App\ViewModels\ISmsModel::class,\App\ViewModels\SmsModel::class);
        /**
         * 🔗 Global Morph Map for all polymorphic relationships
         * ----------------------------------------------------
         * - Ensures morphTo & morphMany resolve correctly
         * - Avoids "Class not found" or "No morph map defined" errors
         * - Keeps DB clean by storing only short type strings (like 'customer', 'user')
         */
        Relation::enforceMorphMap([
            // Forex system models
            'customer' => 'App\Models\Customer',
            'supplier' => 'App\Models\Supplier',
            'forex_remittance' => 'App\Models\ForexRemittance',
            'forex_gain_loss' => 'App\Models\ForexGainLoss',
            'party_payment' => 'App\Models\PartyPayment',

            // Core Laravel / system-level models
            'user' => 'App\Models\User',
            'sale' => 'App\Models\Sale',
            'purchase' => 'App\Models\Purchase',
            'currency' => 'App\Models\Currency',
        ]);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
