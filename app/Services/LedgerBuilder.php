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

    /**
     * Main entry — builds rows and global summary for DataTable
     *
     * @param array $opts
     * @return array ['rows' => [], 'global_summary' => []]
     */
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

            // =========================
            // Setup
            // =========================
            $partyName = $tx->party ? $tx->party->name : 'Unknown';

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

            // Closing Rate Resolve
            $closingRate = (float)$this->rateResolver->getClosingRate($tx);

            // Realised Gain/Loss
            $isInvoice = in_array($tx->voucher_type, ['sale', 'purchase']);

            $realised = 0.0;
            if ($isInvoice) {
                $realised = (float) ForexMatch::where('invoice_id', $tx->id)->sum('realised_amount');
            }

            // Realised breakup
            $realisedBreakup = [];
            if ($isInvoice) {
                $matches = ForexMatch::with('settlement')->where('invoice_id', $tx->id)->get();
                foreach ($matches as $m) {
                    $realisedBreakup[] = [
                        'match_voucher' => $m->settlement ? $m->settlement->voucher_no : null,

                        // ⭐ NEW
                        'settlement_date' => $m->settlement && $m->settlement->transaction_date
                            ? Carbon::parse($m->settlement->transaction_date)->format('d-m-Y')
                            : null,

                        'matched_base'  => (float)$m->matched_base_amount,
                        'inv_rate'      => (float)$m->invoice_rate,
                        'settl_rate'    => (float)$m->settlement_rate,
                        'realised'      => (float)$m->realised_amount,
                    ];
                }
            }

            // Unrealised calculation
            $invoiceMatched    = (float)ForexMatch::where('invoice_id', $tx->id)->sum('matched_base');
            $settlementMatched = (float)ForexMatch::where('settlement_id', $tx->id)->sum('matched_base');

            $remainingBase = $isInvoice
                ? max(0.0, (float)$tx->base_amount - $invoiceMatched)
                : max(0.0, (float)$tx->base_amount - $settlementMatched);

            // Manual closing override support (client rule: only show unrealised when manual provided)
            $manualClosing = $tx->closing_rate_override ?? null;
            $effectiveClosingRate = $manualClosing !== null ? (float)$manualClosing : null;

            $unrealised = 0.0;
            if ($remainingBase > 0) {
                if ($isInvoice) {
                    $unrealised = $this->gainLossService->calcUnrealised(
                        $remainingBase,
                        $effectiveClosingRate,
                        $rowRate,
                        'invoice'
                    );
                } else {
                    $unrealised = $this->gainLossService->calcUnrealised(
                        $remainingBase,
                        $effectiveClosingRate,
                        $rowRate,
                        'advance',
                        $rowRate,
                        $manualClosing
                    );
                }
            }

            // DIFF Calculation (unchanged)
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

            // Direction of remaining base
            $direction = null;
            if ($remainingBase > 0) {
                if (in_array($tx->voucher_type, ['receipt', 'purchase'])) {
                    $direction = 'CR';
                } else {
                    $direction = 'DR';
                }
            }

            // remaining_local_value — valued using the voucher exchange rate (as per your rule)
            $remaining_local_value = $remainingBase * $rowRate;

            // ROW OUTPUT (includes direction + remaining_base + remaining_local_value)
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

                // New useful keys for client JS
                'direction' => $direction,                    // 'CR' | 'DR' | null
                'remaining_base' => round($remainingBase, 4), // numeric (not formatted string)
                'remaining_local_value' => round($remaining_local_value, 4), // numeric

                // ⭐ REALISED BREAKUP INCLUDED
                'realised_breakup' => $realisedBreakup,

                // ⭐ ACTION URLs
                'edit_url'   => route('sales.edit', $tx->id),
                'delete_url' => route('forex.remittance.destroy', $tx->id),
            ];
        }

        // Build global summary using new FIFO-based helper
        $globalSummary = $this->buildGlobalSummaryFromRows($rows);

        return [
            'rows' => $rows,
            'global_summary' => $globalSummary
        ];
    }

    /**
     * Build global CR/DR summary from already-built rows using FIFO matching.
     * Returns an array matching previous 'global' shape plus detailed pieces.
     *
     * Logic:
     * - Build CR list (receipts + purchases) and DR list (sales + payments) preserving row order.
     * - FIFO-match base amounts between sides (consume opposite sides).
     * - Any remaining on a side is valued using that voucher's own exchange rate (voucher.rate).
     *
     * @param array $rows
     * @return array
     */
    protected function buildGlobalSummaryFromRows(array $rows): array
    {
        $CR = [];
        $DR = [];

        // Build ordered lists from rows (preserve input order)
        foreach ($rows as $r) {
            $baseDr = floatval(str_replace(',', '', $r['base_debit'] ?? 0));
            $baseCr = floatval(str_replace(',', '', $r['base_credit'] ?? 0));
            $rate   = floatval($r['exch_rate'] ?? 0);
            $vno    = $r['vch_no'] ?? null;

            if (in_array($r['vch_type'], ['Receipt', 'Purchase'])) {
                if ($baseCr > 0) {
                    $CR[] = [
                        'vno' => $vno,
                        'base' => $baseCr,
                        'rate' => $rate
                    ];
                }
            }

            if (in_array($r['vch_type'], ['Sale', 'Payment'])) {
                if ($baseDr > 0) {
                    $DR[] = [
                        'vno' => $vno,
                        'base' => $baseDr,
                        'rate' => $rate
                    ];
                }
            }
        }

        // Keep copies for FIFO consumption
        $A = $CR; // copy
        $B = $DR; // copy

        $i = 0;
        $j = 0;
        while ($i < count($A) && $j < count($B)) {
            if ($A[$i]['base'] == 0) {
                $i++;
                continue;
            }
            if ($B[$j]['base'] == 0) {
                $j++;
                continue;
            }

            $consume = min($A[$i]['base'], $B[$j]['base']);

            $A[$i]['base'] -= $consume;
            $B[$j]['base'] -= $consume;

            if ($A[$i]['base'] == 0) $i++;
            if ($B[$j]['base'] == 0) $j++;
        }

        // After FIFO matching, find remaining CR and DR totals and their local valuations
        $remainingCR_base = 0.0;
        $remainingCR_local = 0.0;
        $appliedCR_voucher = null;
        $appliedCR_rate = 0.0;

        foreach ($A as $entry) {
            if ($entry['base'] > 0) {
                $remainingCR_base += $entry['base'];
                $remainingCR_local += $entry['base'] * $entry['rate'];
                // For applied voucher/rate we choose the last remaining voucher (similar to previous behaviour)
                $appliedCR_voucher = $entry['vno'];
                $appliedCR_rate = $entry['rate'];
            }
        }

        $remainingDR_base = 0.0;
        $remainingDR_local = 0.0;
        $appliedDR_voucher = null;
        $appliedDR_rate = 0.0;

        foreach ($B as $entry) {
            if ($entry['base'] > 0) {
                $remainingDR_base += $entry['base'];
                $remainingDR_local += $entry['base'] * $entry['rate'];
                $appliedDR_voucher = $entry['vno'];
                $appliedDR_rate = $entry['rate'];
            }
        }

        // Total base sums (for compatibility with old shape)
        $totalCR_base = array_reduce($CR, function ($carry, $it) {
            return $carry + $it['base'];
        }, 0.0) + $remainingCR_base;
        $totalDR_base = array_reduce($DR, function ($carry, $it) {
            return $carry + $it['base'];
        }, 0.0) + $remainingDR_base;

        // Decide net: earlier behavior reported local_net as remaining_base * appliedRate (single side).
        // But your requested logic: local_net = CR_local - DR_local (side-wise valuation)
        $CR_local_total = $remainingCR_local; // CR side remaining local valuation
        $DR_local_total = $remainingDR_local; // DR side remaining local valuation

        $net_local = $CR_local_total - $DR_local_total;

        $sign = $net_local > 0 ? 'Cr' : ($net_local < 0 ? 'Dr' : 'Nil');
        // For compatibility with older front-end keys, compute remaining_base_global (absolute)
        $remaining_base_global = ($remainingCR_base ?: $remainingDR_base);

        // As older code used 'applied_rate' and 'applied_voucher' pick the last remaining voucher from whichever side had remaining
        $appliedRate = 0;
        $appliedVoucher = null;
        if ($remainingCR_base > 0) {
            $appliedRate = $appliedCR_rate;
            $appliedVoucher = $appliedCR_voucher;
        } elseif ($remainingDR_base > 0) {
            $appliedRate = $appliedDR_rate;
            $appliedVoucher = $appliedDR_voucher;
        }

        return [
            'totalCR_base' => round($totalCR_base, 4),
            'totalDR_base' => round($totalDR_base, 4),
            'remaining_base' => round($remaining_base_global, 4),
            'applied_rate' => round($appliedRate, 6),
            'applied_voucher' => $appliedVoucher,
            // preserve older key name local_net but now store the *difference* (CR_local - DR_local)
            'local_net' => round($net_local, 4),
            'sign' => $sign,

            // extra debug/clarity fields you can use in UI if needed
            'cr_remaining_base' => round($remainingCR_base, 4),
            'cr_remaining_local' => round($CR_local_total, 4),
            'cr_applied_voucher' => $appliedCR_voucher,
            'dr_remaining_base' => round($remainingDR_base, 4),
            'dr_remaining_local' => round($DR_local_total, 4),
            'dr_applied_voucher' => $appliedDR_voucher,
        ];
    }
}
