<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\ForexMatch;
use App\Models\ForexRate;
use Carbon\Carbon;

class LedgerBuilder
{
    protected $gainLossService;
    protected $rateResolver;

    public function __construct(GainLossService $gls, RateResolver $rateResolver)
    {
        $this->gainLossService = $gls;
        $this->rateResolver = $rateResolver;
    }

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

            // Base / Local DR-CR mapping (same as before)
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

            $rowRate = (float)($tx->exchange_rate ?? 0.0);

            // RESOLVE CLOSING RATE (using RateResolver)
            $closingRate = (float)$this->rateResolver->getClosingRate($tx);

            // realised amount: sum of realised_amount from forex_matches where tx participates
            $isInvoice = in_array($tx->voucher_type, ['sale', 'purchase']);

            $realisedFromInvoice = (float) ForexMatch::where('invoice_id', $tx->id)->sum('realised_amount');
            $realised = $isInvoice ? $realisedFromInvoice : 0.0;

            // matched base sums
            $invoiceMatched = (float)ForexMatch::where('invoice_id', $tx->id)->sum('matched_base');
            $settlementMatched = (float)ForexMatch::where('settlement_id', $tx->id)->sum('matched_base');

            // remaining base
            $remainingBase = $isInvoice
                ? max(0.0, (float)$tx->base_amount - $invoiceMatched)
                : max(0.0, (float)$tx->base_amount - $settlementMatched);

            // Get invoiceRateOverride if present on tx (special-case)
            $invoiceRateOverride = $tx->closing_rate_override ?? null;

            // unrealised calculation (use GainLossService with resolver results)
            $unrealised = 0.0;
            if ($remainingBase > 0) {
                if ($isInvoice) {
                    $unrealised = $this->gainLossService->calcUnrealised(
                        $remainingBase,
                        $closingRate,
                        $rowRate,
                        'invoice'
                    );
                } else {
                    // settlement (advance)
                    $unrealised = $this->gainLossService->calcUnrealised(
                        $remainingBase,
                        $closingRate,
                        $rowRate,
                        'advance',
                        $rowRate,
                        $invoiceRateOverride
                    );
                }
            }

            // compute diff field as before (keeps your previous logic)
            $diff = "";
            if ($isInvoice) {
                if ($remainingBase == 0 && $invoiceMatched > 0) {
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

                // ⭐ New URLs for action column
                'edit_url' => route('sales.edit', $tx->id),
                'delete_url' => route('forex.remittance.destroy', $tx->id),
            ];
        }

        return $rows;
    }


    public function buildCurrencyWise(array $opts): array
    {
        $q = Transaction::with(['party', 'baseCurrency', 'localCurrency'])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        // BASE CURRENCY FILTER
        if (!empty($opts['base_currency_id']) && $opts['base_currency_id'] != 0) {
            $q->where('base_currency_id', intval($opts['base_currency_id']));
        }

        // LOCAL CURRENCY FILTER
        if (!empty($opts['local_currency_id']) && $opts['local_currency_id'] != 0) {
            $q->where('local_currency_id', intval($opts['local_currency_id']));
        }

        // DATE RANGE
        if (!empty($opts['starting_date']) && !empty($opts['ending_date'])) {
            $start = Carbon::parse($opts['starting_date'])->toDateString();
            $end   = Carbon::parse($opts['ending_date'])->toDateString();
            $q->whereBetween('transaction_date', [$start, $end]);
        }

        $txs = $q->get();
        $rows = [];
        $sn = 1;

        foreach ($txs as $tx) {
            $rows[] = $this->formatTxRow($tx, $sn++);
        }

        return $rows;
    }


    public function buildPartyWise(array $opts): array
    {
        $q = Transaction::with(['party', 'baseCurrency', 'localCurrency'])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        // ONLY THIS PARTY
        if (!empty($opts['party_id'])) {
            $q->where('party_id', intval($opts['party_id']));
        }

        // DATE FILTER
        if (!empty($opts['starting_date']) && !empty($opts['ending_date'])) {
            $start = Carbon::parse($opts['starting_date'])->toDateString();
            $end   = Carbon::parse($opts['ending_date'])->toDateString();
            $q->whereBetween('transaction_date', [$start, $end]);
        }

        // now reuse SAME row-building logic
        $txs = $q->get();

        $rows = [];
        $sn = 1;

        foreach ($txs as $tx) {
            $rows[] = $this->formatTxRow($tx, $sn++); // SAME formatting function
        }

        return $rows;
    }
}
