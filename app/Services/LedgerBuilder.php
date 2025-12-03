<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\ForexMatch;
use App\Models\ForexRate;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class LedgerBuilder
{
    protected $gainLossService;

    public function __construct(GainLossService $gls)
    {
        $this->gainLossService = $gls;
    }

    /**
     * Build rows for DataTable with filters.
     *
     * Filters supported in $opts:
     *  - party_type (customer|supplier)
     *  - currency_id (int) -- matches base_currency_id OR local_currency_id (0 = all)
     *  - starting_date (Y-m-d)
     *  - ending_date (Y-m-d)
     *
     * Returns: array of rows ready for DataTable, each row includes 'realised' and 'unrealised' numeric values.
     *
     * NOTE: This function intentionally computes realised/unrealised using stored forex_matches table.
     */
    public function buildForDataTable(array $opts = []): array
    {
        $q = Transaction::with(['party', 'baseCurrency', 'localCurrency'])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        if (!empty($opts['party_type'])) {
            $q->where('party_type', $opts['party_type']);
        }

        if (!empty($opts['currency_id'])) {
            $cid = intval($opts['currency_id']);
            if ($cid !== 0) {
                $q->where(function ($wr) use ($cid) {
                    $wr->where('base_currency_id', $cid)
                        ->orWhere('local_currency_id', $cid);
                });
            }
        }

        if (!empty($opts['starting_date']) && !empty($opts['ending_date'])) {
            $start = Carbon::createFromFormat('Y-m-d', $opts['starting_date'])->toDateString();
            $end = Carbon::createFromFormat('Y-m-d', $opts['ending_date'])->toDateString();
            $q->whereBetween('transaction_date', [$start, $end]);
        }

        $txs = $q->get();

        $rows = [];
        $sn = 1;

        foreach ($txs as $tx) {
            $partyName = $tx->party ? $tx->party->name : 'Unknown Party';

            // Base / Local DR-CR mapping
            $baseDebit = $baseCredit = $localDebit = $localCredit = 0.0;
            switch ($tx->voucher_type) {
                case 'sale':
                    $baseDebit = (float)$tx->base_amount;
                    $localDebit = (float)$tx->local_amount;
                    break;
                case 'purchase':
                    $baseCredit = (float)$tx->base_amount;
                    $localCredit = (float)$tx->local_amount;
                    break;
                case 'receipt':
                    $baseCredit = (float)$tx->base_amount;
                    $localCredit = (float)$tx->local_amount;
                    break;
                case 'payment':
                    $baseDebit = (float)$tx->base_amount;
                    $localDebit = (float)$tx->local_amount;
                    break;
            }

            // Row authoritative rate (invoice/settlement)
            $rowRate = (float)($tx->exchange_rate ?? 0.0);

            // Closing rate resolution (row.closing_rate -> closing for end date -> latest -> avg/rowRate)
            $closingRate = null;
            if (!is_null($tx->closing_rate)) {
                $closingRate = (float)$tx->closing_rate;
            } else {
                if (!empty($opts['ending_date'])) {
                    $baseCode = $tx->baseCurrency ? $tx->baseCurrency->code : null;
                    $localCode = $tx->localCurrency ? $tx->localCurrency->code : null;
                    if ($baseCode && $localCode) {
                        $closingRate = ForexRate::getClosingRate($baseCode, $localCode, $opts['ending_date']);
                    }
                }
                if ($closingRate === null) {
                    $baseCode = $tx->baseCurrency ? $tx->baseCurrency->code : null;
                    $localCode = $tx->localCurrency ? $tx->localCurrency->code : null;
                    if ($baseCode && $localCode) {
                        $closingRate = ForexRate::getClosingRate($baseCode, $localCode, now()->toDateString());
                    }
                }
                if ($closingRate === null) {
                    // fallback to avg_rate or rowRate
                    $closingRate = (float)($tx->avg_rate ?? $rowRate);
                }
            }

            // realised amount: sum of realised_amount from forex_matches where tx participates
            $realisedFromInvoice = (float)ForexMatch::where('invoice_id', $tx->id)->sum('realised_amount');
            $realisedFromSettlement = (float)ForexMatch::where('settlement_id', $tx->id)->sum('realised_amount');
            // 1) Identify invoice-type
            $isInvoice = in_array($tx->voucher_type, ['sale', 'purchase']);

            // 2) Realised only for invoices
            $realisedFromInvoice = (float) ForexMatch::where('invoice_id', $tx->id)->sum('realised_amount');

            if ($isInvoice) {
                // sale & purchase only
                $realised = $realisedFromInvoice;
            } else {
                // receipt & payment NEVER show realised
                $realised = 0;
            }


            // matched base sums
            $invoiceMatched = (float)ForexMatch::where('invoice_id', $tx->id)->sum('matched_base');
            $settlementMatched = (float)ForexMatch::where('settlement_id', $tx->id)->sum('matched_base');

            // remaining base
            $isInvoice = in_array($tx->voucher_type, ['sale', 'purchase']);
            $remainingBase = $isInvoice
                ? max(0.0, (float)$tx->base_amount - $invoiceMatched)
                : max(0.0, (float)$tx->base_amount - $settlementMatched);

            // unrealised calculation (V7 rules implemented)
            $unrealised = 0.0;
            if ($remainingBase > 0 && $closingRate !== null) {
                if ($isInvoice) {
                    $unrealised = round($remainingBase * ($closingRate - $rowRate), 4);
                } else {
                    // settlement (advance)
                    $unrealised = round($remainingBase * ($rowRate - $closingRate), 4);
                }
            }

            // calculate diff (effective rate indicator)
            $diff = "";
            if ($isInvoice) {
                if ($remainingBase == 0 && $invoiceMatched > 0) {
                    // compute weighted effective settlement rate
                    $matches = ForexMatch::where('invoice_id', $tx->id)->get();
                    $totalBase = 0.0;
                    $weightedSettlement = 0.0;
                    foreach ($matches as $m) {
                        $settTx = $m->settlement;
                        $settRate = $settTx ? (float)$settTx->exchange_rate : null;
                        if ($settRate !== null) {
                            $weightedSettlement += (float)$m->matched_base * $settRate;
                            $totalBase += (float)$m->matched_base;
                        }
                    }
                    if ($totalBase > 0) {
                        $effectiveRate = $weightedSettlement / $totalBase;
                        $diff = round($effectiveRate - $rowRate, 6);
                    }
                } elseif ($remainingBase > 0) {
                    $diff = round($closingRate - $rowRate, 6);
                }
            } else {
                if ($settlementMatched == 0 && $remainingBase > 0) {
                    $diff = round($rowRate - $closingRate, 6);
                } else {
                    $diff = "";
                }
            }

            $rows[] = [
                'id' => $tx->id,
                'sn' => $sn++,
                'date' => $tx->transaction_date instanceof \DateTime ? $tx->transaction_date->format('Y-m-d') : $tx->transaction_date,
                'particulars' => ($tx->party ? $tx->party->name : 'Unknown') . ' — ' . ucfirst($tx->voucher_type) . ' (' . $tx->voucher_no . ')',
                'vch_type' => ucfirst($tx->voucher_type),
                'vch_no' => $tx->voucher_no,
                'exch_rate' => number_format($rowRate, 4, '.', ''),
                'base_debit' => $baseDebit ? number_format($baseDebit, 4, '.', ',') : '',
                'base_credit' => $baseCredit ? number_format($baseCredit, 4, '.', ',') : '',
                'local_debit' => $localDebit ? number_format($localDebit, 4, '.', ',') : '',
                'local_credit' => $localCredit ? number_format($localCredit, 4, '.', ',') : '',
                'avg_rate' => isset($tx->avg_rate) ? number_format((float)$tx->avg_rate, 6, '.', '') : '',
                'closing_rate' => $closingRate !== null ? number_format((float)$closingRate, 6, '.', '') : '',
                'diff' => $diff === "" ? "" : number_format((float)$diff, 6, '.', ''),
                'realised' => round($realised, 4),
                'unrealised' => round($unrealised, 4),
                'remarks' => $tx->remarks ?? '',
            ];
        }

        return $rows;
    }
}
