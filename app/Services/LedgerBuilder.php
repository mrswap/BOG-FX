<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\ForexMatch;
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

        // FILTER: Invoice-wise allowed transactions
        if (!empty($opts['allowed_tx_ids'])) {
            $q->whereIn('id', $opts['allowed_tx_ids']);
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
            $end   = Carbon::createFromFormat('Y-m-d', $opts['ending_date'])->toDateString();
            $q->whereBetween('transaction_date', [$start, $end]);
        }

        $txs = $q->get();

        $rows = [];
        $sn = 1;

        foreach ($txs as $tx) {

            $partyName = $tx->party ? $tx->party->name : 'Unknown';

            // =========================
            // DR/CR Calculation
            // =========================
            $baseDebit = $baseCredit = $localDebit = $localCredit = 0.0;

            switch ($tx->voucher_type) {
                case 'sale':
                    $baseDebit  = (float)$tx->base_amount;
                    $localDebit = (float)$tx->local_amount;
                    break;

                case 'purchase':
                    $baseCredit  = (float)$tx->base_amount;
                    $localCredit = (float)$tx->local_amount;
                    break;

                case 'receipt':
                    $baseCredit  = (float)$tx->base_amount;
                    $localCredit = (float)$tx->local_amount;
                    break;

                case 'payment':
                    $baseDebit  = (float)$tx->base_amount;
                    $localDebit = (float)$tx->local_amount;
                    break;
            }

            $rowRate = (float)($tx->exchange_rate ?? 0.0);

            // ===============================
            // Closing Rate Resolve
            // ===============================
            $closingRate = (float)$this->rateResolver->getClosingRate($tx);

            // ===============================
            // Realised Gain/Loss
            // ===============================
            $isInvoice = in_array($tx->voucher_type, ['sale', 'purchase']);

            $realised = 0.0;

            if ($isInvoice) {
                $realised = (float) ForexMatch::where('invoice_id', $tx->id)->sum('realised_amount');
            }

            // ===============================
            // REALISED BREAKUP (⭐ REQUIRED)
            // ===============================
            $realisedBreakup = [];

            if ($isInvoice) {
                $matches = ForexMatch::with('settlement')->where('invoice_id', $tx->id)->get();

                foreach ($matches as $m) {
                    $realisedBreakup[] = [
                        'match_voucher' => $m->settlement ? $m->settlement->voucher_no : null,
                        'matched_base'  => (float)$m->matched_base_amount,
                        'inv_rate'      => (float)$m->invoice_rate,
                        'settl_rate'    => (float)$m->settlement_rate,
                        'realised'      => (float)$m->realised_amount,
                    ];
                }
            }

            // ===============================
            // Unrealised Gain/Loss
            // ===============================
            $invoiceMatched    = (float)ForexMatch::where('invoice_id', $tx->id)->sum('matched_base');
            $settlementMatched = (float)ForexMatch::where('settlement_id', $tx->id)->sum('matched_base');

            $remainingBase = $isInvoice
                ? max(0.0, (float)$tx->base_amount - $invoiceMatched)
                : max(0.0, (float)$tx->base_amount - $settlementMatched);

            // ⭐ Manual Closing Rate Override Support
            $manualClosing = $tx->closing_rate_override ?? null;

            // PRIORITY:
            // 1. Manual entered rate
            // 2. Auto closing rate resolver
            $effectiveClosingRate = ($manualClosing !== null && $manualClosing !== '')
                ? (float)$manualClosing
                : (float)$closingRate;

            $unrealised = 0.0;

            if ($remainingBase > 0) {

                if ($isInvoice) {
                    $unrealised = $this->gainLossService->calcUnrealised(
                        $remainingBase,
                        $effectiveClosingRate,   // ⭐ updated
                        $rowRate,
                        'invoice'
                    );
                } else {
                    $unrealised = $this->gainLossService->calcUnrealised(
                        $remainingBase,
                        $effectiveClosingRate,   // ⭐ updated
                        $rowRate,
                        'advance',
                        $rowRate,
                        $manualClosing           // ⭐ updated
                    );
                }
            }

            // ===============================
            // DIFF Calculation
            // ===============================
            $diff = "";

            if ($isInvoice) {
                if ($remainingBase == 0 && $invoiceMatched > 0) {

                    $matches = ForexMatch::with('settlement')->where('invoice_id', $tx->id)->get();

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
                }
            }
            // Direction: what side does remaining belong to?
            // Receipt & Purchase = CR side (they increase what party owes)
            // Sale & Payment = DR side (they reduce what party owes)
            $direction = null;

            if ($remainingBase > 0) {
                if (in_array($tx->voucher_type, ['receipt', 'purchase'])) {
                    $direction = 'CR';
                } else {
                    $direction = 'DR';
                }
            }


            // ===============================
            // ROW OUTPUT
            // ===============================
            $rows[] = [
                'id'         => $tx->id,
                'sn'         => $sn++,
                'date'       => $tx->transaction_date instanceof \DateTime
                    ? $tx->transaction_date->format('Y-m-d')
                    : $tx->transaction_date,

                'particulars' => ($tx->party ? $tx->party->name : 'Unknown')
                    . ' — ' . ucfirst($tx->voucher_type)
                    . ' (' . $tx->voucher_no . ')',

                'vch_type' => ucfirst($tx->voucher_type),
                'vch_no'   => $tx->voucher_no,

                'exch_rate' => number_format($rowRate, 4, '.', ''),

                'base_debit'  => $baseDebit  ? number_format($baseDebit,  4, '.', ',') : '',
                'base_credit' => $baseCredit ? number_format($baseCredit, 4, '.', ',') : '',
                'local_debit' => $localDebit ? number_format($localDebit, 4, '.', ',') : '',
                'local_credit' => $localCredit ? number_format($localCredit, 4, '.', ',') : '',

                'avg_rate'    => isset($tx->avg_rate) ? number_format((float)$tx->avg_rate, 6, '.', '') : '',
                'closing_rate' => number_format((float)$closingRate, 6, '.', ''),
                'diff'        => $diff === "" ? "" : number_format((float)$diff, 6, '.', ''),

                'realised'   => round($realised, 4),
                'unrealised' => round($unrealised, 4),

                'remarks' => $tx->remarks ?? '',

                // ⭐ REALISED BREAKUP INCLUDED
                'realised_breakup' => $realisedBreakup,

                // ⭐ ACTION URLs
                'edit_url'   => route('sales.edit', $tx->id),
                'delete_url' => route('forex.remittance.destroy', $tx->id),

                'remaining_base' => $remainingBase,
                'remaining_local_value' => $remainingBase * $effectiveClosingRate,
                'direction' => $direction,


            ];
        }

        return $rows;
    }
}
