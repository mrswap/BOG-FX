<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ForexMatch
 *
 * Represents a matching record between an invoice transaction and a settlement transaction.
 *
 * Columns:
 *  - id
 *  - party_id
 *  - invoice_id
 *  - settlement_id
 *  - matched_base
 *  - realised_amount
 *  - created_at
 *  - updated_at
 */
class ForexMatch extends Model
{
    protected $table = 'forex_matches';

    protected $fillable = [
        'party_id',
        'invoice_id',
        'settlement_id',
        'matched_base',
        'realised_amount',
    ];

    protected $casts = [
        'matched_base' => 'float',
        'realised_amount' => 'float',
    ];

    /**
     * Relations
     */
    public function party()
    {
        return $this->belongsTo(\App\Models\Party::class, 'party_id');
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Transaction::class, 'invoice_id');
    }

    public function settlement()
    {
        return $this->belongsTo(\App\Models\Transaction::class, 'settlement_id');
    }

    /**
     * Scope: for a given party
     */
    public function scopeForParty($query, int $partyId)
    {
        return $query->where('party_id', $partyId);
    }

    /**
     * Scope: matches where invoice is given
     */
    public function scopeForInvoice($query, int $invoiceId)
    {
        return $query->where('invoice_id', $invoiceId);
    }

    /**
     * Scope: matches where settlement is given
     */
    public function scopeForSettlement($query, int $settlementId)
    {
        return $query->where('settlement_id', $settlementId);
    }

    /**
     * Helper: get total realised for a given invoice
     */
    public static function realisedSumForInvoice(int $invoiceId): float
    {
        return (float) self::where('invoice_id', $invoiceId)->sum('realised_amount');
    }

    /**
     * Helper: get total matched base for invoice/settlement
     */
    public static function matchedBaseSumForInvoice(int $invoiceId): float
    {
        return (float) self::where('invoice_id', $invoiceId)->sum('matched_base');
    }

    public static function matchedBaseSumForSettlement(int $settlementId): float
    {
        return (float) self::where('settlement_id', $settlementId)->sum('matched_base');
    }
}
