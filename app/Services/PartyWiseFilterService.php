<?php

namespace App\Services;

use App\Models\Transaction;

class PartyWiseFilterService
{
    /**
     * Filters transactions by:
     * - party_id
     * - date range
     * - transaction type group (customer/supplier)
     * Returns allowed transaction IDs.
     */
    public function filter(array $opts): array
    {
        $q = Transaction::query();

        // PARTY FILTER
        if (!empty($opts['party_id'])) {
            $q->where('party_id', $opts['party_id']);
        }

        // DATE FILTER
        if (!empty($opts['starting_date']) && !empty($opts['ending_date'])) {
            $q->whereBetween('transaction_date', [
                $opts['starting_date'],
                $opts['ending_date']
            ]);
        }

        // TRANSACTION GROUP FILTER
        if (!empty($opts['txn_group'])) {

            if ($opts['txn_group'] === 'customer_side') {
                // Sale + Receipt
                $q->whereIn('voucher_type', ['sale', 'receipt']);

            } elseif ($opts['txn_group'] === 'supplier_side') {
                // Purchase + Payment
                $q->whereIn('voucher_type', ['purchase', 'payment']);
            }
        }

        return $q->pluck('id')->toArray();
    }
}
