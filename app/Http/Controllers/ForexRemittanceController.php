<?php

namespace App\Http\Controllers;

use App\Models\ForexRemittance;
use App\Models\Currency;
use App\Services\ForexFifoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Party;
use DB;
use Carbon\Carbon;


class ForexRemittanceController extends Controller
{
    public function index()
    {
        $currency_list = Currency::active()->get();
        $starting_date = date('Y-m-01');
        $ending_date   = date('Y-m-d');

        return view('backend.forex.index', compact('currency_list', 'starting_date', 'ending_date'));
    }

    public function create()
    {
        $currency_list = Currency::active()->get();
        $party = \App\Models\Party::all();
        return view('backend.forex.create', compact('currency_list', 'party'));
    }

    // inside App\Http\Controllers\ForexRemittanceController

    public function store(Request $request)
    {
        $request->validate([
            'party_id' => 'required|integer',
            'voucher_type' => 'required|string',
            'voucher_no' => 'required|string',
            'transaction_date' => 'required|date',
            'base_currency_id' => 'required|integer',
            'local_currency_id' => 'required|integer',
            'base_amount' => 'required|numeric',
            'exchange_rate' => 'required|numeric',
        ]);

        $partyId = $request->party_id;
        $voucherType = strtolower($request->voucher_type);

        // MAP ledger + direction
        $direction = in_array($voucherType, ['sale', 'payment']) ? 'debit' : 'credit';
        $ledgerType = in_array($voucherType, ['sale', 'receipt']) ? 'customer' : 'supplier';

        $txn = new ForexRemittance();
        $txn->party_id = $partyId;
        $txn->ledger_type = $ledgerType;
        $txn->voucher_type = $voucherType;
        $txn->voucher_no = $request->voucher_no;
        $txn->transaction_date = $request->transaction_date;
        $txn->base_currency_id = $request->base_currency_id;
        $txn->local_currency_id = $request->local_currency_id;

        $txn->base_amount = $request->base_amount;
        $txn->exchange_rate = $request->exchange_rate;
        $txn->local_amount = round($request->base_amount * $request->exchange_rate, 4);

        // FX fields initialization
        $txn->direction = $direction;
        $txn->remaining_base_amount = $request->base_amount;
        $txn->settled_base_amount = 0;

        $txn->realised_gain = 0;
        $txn->realised_loss = 0;
        $txn->unrealised_gain = 0;
        $txn->unrealised_loss = 0;

        $txn->avg_rate = null;
        $txn->closing_rate = null;

        $txn->save();

        // RUN FIFO AFTER SAVE
        app(\App\Services\ForexFifoService::class)
            ->applyFifoFor(
                $partyId,
                $ledgerType,
                $request->base_currency_id
            );

        return response()->json([
            'status' => 200,
            'message' => 'Forex transaction saved & FIFO applied successfully',
        ]);
    }

    public function forexRemittanceData(Request $request)
    {
        // Base query for counts & totals
        $baseQuery = ForexRemittance::with(['party', 'baseCurrency', 'localCurrency'])
            ->orderBy('transaction_date')
            ->orderBy('id');

        $rows = $baseQuery->get();

        $data = [];
        $sn = 1;

        // TOTALS INITIALISE
        $totalRealisedGain = 0;
        $totalRealisedLoss = 0;
        $totalUnrealisedGain = 0;
        $totalUnrealisedLoss = 0;

        foreach ($rows as $t) {

            // Add to totals
            $totalRealisedGain    += floatval($t->realised_gain);
            $totalRealisedLoss    += floatval($t->realised_loss);
            $totalUnrealisedGain  += floatval($t->unrealised_gain);
            $totalUnrealisedLoss  += floatval($t->unrealised_loss);

            // BASE debit/credit output
            $baseDebit  = $t->direction === 'debit'
                ? number_format($t->base_amount, 4) . " ({$t->baseCurrency->code})"
                : 0;

            $baseCredit = $t->direction === 'credit'
                ? number_format($t->base_amount, 4) . " ({$t->baseCurrency->code})"
                : 0;

            // LOCAL debit/credit output
            $localDebit  = $t->direction === 'debit'
                ? number_format($t->local_amount, 4) . " ({$t->localCurrency->code})"
                : 0;

            $localCredit = $t->direction === 'credit'
                ? number_format($t->local_amount, 4) . " ({$t->localCurrency->code})"
                : 0;

            // REALISED FORMAT
            $realised = "";
            if ($t->realised_gain > 0 && $t->realised_loss > 0) {
                $realised = "+" . number_format($t->realised_gain, 4)
                    . " / -" . number_format($t->realised_loss, 4);
            } elseif ($t->realised_gain > 0) {
                $realised = "+" . number_format($t->realised_gain, 4);
            } elseif ($t->realised_loss > 0) {
                $realised = "-" . number_format($t->realised_loss, 4);
            }

            // UNREALISED FORMAT
            $unrealised = "";
            if ($t->unrealised_gain > 0 && $t->unrealised_loss > 0) {
                $unrealised = "+" . number_format($t->unrealised_gain, 4)
                    . " / -" . number_format($t->unrealised_loss, 4);
            } elseif ($t->unrealised_gain > 0) {
                $unrealised = "+" . number_format($t->unrealised_gain, 4);
            } elseif ($t->unrealised_loss > 0) {
                $unrealised = "-" . number_format($t->unrealised_loss, 4);
            }

            // DIFF = closing rate - invoice rate
            $diff = null;
            if ($t->closing_rate !== null) {
                $diff = number_format($t->closing_rate - $t->exchange_rate, 6);
            }

            // REMARKS
            $remarks = "";
            if ($t->remaining_base_amount > 0) {
                $remarks = "Remaining Base: " . number_format($t->remaining_base_amount, 4);
            }

            // Final Row
            $data[] = [
                "sn"             => $sn++,
                "date"           => Carbon::parse($t->transaction_date)->format('d-m-Y'),
                "particulars"    => $t->party->name ?? "",
                "vch_type"       => strtoupper($t->voucher_type),
                "vch_no"         => $t->voucher_no,
                "exch_rate"      => number_format($t->exchange_rate, 6),

                "base_debit"     => $baseDebit,
                "base_credit"    => $baseCredit,
                "local_debit"    => $localDebit,
                "local_credit"   => $localCredit,

                "avg_rate"       => $t->avg_rate,
                "closing_rate"   => number_format($t->closing_rate, 6),

                "diff"           => $diff,
                "realised"       => $realised,
                "unrealised"     => $unrealised,
                "remarks"        => $remarks,
            ];
        }

        // FINAL NET GAIN/LOSS
        $finalNet = ($totalRealisedGain - $totalRealisedLoss)
            + ($totalUnrealisedGain - $totalUnrealisedLoss);

        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $baseQuery->count(),
            'recordsFiltered' => $baseQuery->count(),
            'data' => $data,
            'totals' => [
                'realised_gain'      => round($totalRealisedGain, 2),
                'realised_loss'      => round($totalRealisedLoss, 2),
                'unrealised_gain'    => round($totalUnrealisedGain, 2),
                'unrealised_loss'    => round($totalUnrealisedLoss, 2),
                'final_gain_loss'    => round($finalNet, 2),
            ]
        ]);
    }




    public function reportData(Request $request)
    {
        $type = $request->type;
        $start = $request->starting_date ?: date('Y-m-01');
        $end   = $request->ending_date ?: date('Y-m-d');
        $currency = $request->currency_id;
        $party    = $request->party_id;
        $voucher  = $request->voucher_no;
        $closingRateInput = $request->closing_rate_global;

        $q = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            ->whereBetween('transaction_date', [$start . " 00:00:00", $end . " 23:59:59"])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        if (!empty($currency) && $currency != 0) $q->where('base_currency_id', $currency);
        if (!empty($party)) $q->where('party_id', $party);
        if (!empty($voucher)) $q->where('voucher_no', $voucher);

        switch ($type) {
            case 'invoice':
                $q->whereIn('voucher_type', ['sale', 'purchase']);
                break;
            case 'realised':
                $q->where(function ($z) {
                    $z->where('realised_gain', '>', 0)->orWhere('realised_loss', '>', 0);
                });
                break;
            case 'unrealised':
                $q->where(function ($z) {
                    $z->where('unrealised_gain', '>', 0)->orWhere('unrealised_loss', '>', 0);
                });
                break;
        }

        $recordsTotal = $q->count();
        $startRow = (int)$request->start;
        $length = (int)$request->length ?: 100;

        $rowsDB = (clone $q)->offset($startRow)->limit($length)->get();

        if ($request->filled('closing_rate_global')) {
            $closingRate = (float)$closingRateInput;
        } else {
            $closingRate = (clone $q)->orderBy('transaction_date', 'desc')->orderBy('id', 'desc')->value('exchange_rate');
            if (!$closingRate && !empty($currency) && $currency != 0) {
                $closingRate = optional(Currency::find($currency))->exchange_rate;
            }
        }

        $rows = [];
        $sn = $startRow + 1;

        $totalRealisedGain = 0.0;
        $totalRealisedLoss = 0.0;
        $totalUnrealisedGain = 0.0;
        $totalUnrealisedLoss = 0.0;

        foreach ($rowsDB as $rawFx) {
            $fx = $rawFx->fresh();

            $currBase  = $fx->baseCurrency->code ?? '';
            $currLocal = $fx->localCurrency->code ?? '';

            $baseDebit  = $fx->direction == 'debit' ? $fx->base_amount : 0;
            $baseCredit = $fx->direction == 'credit' ? $fx->base_amount : 0;
            $localDebit  = $fx->direction == 'debit' ? $fx->local_amount : 0;
            $localCredit = $fx->direction == 'credit' ? $fx->local_amount : 0;

            $realised   = $fx->realised_gain - $fx->realised_loss;
            $unrealised = $fx->unrealised_gain - $fx->unrealised_loss;

            $totalRealisedGain += (float)$fx->realised_gain;
            $totalRealisedLoss += (float)$fx->realised_loss;
            $totalUnrealisedGain += (float)$fx->unrealised_gain;
            $totalUnrealisedLoss += (float)$fx->unrealised_loss;

            $rows[] = [
                'sn' => $sn++,
                'date' => $fx->transaction_date->format('d-m-Y'),
                'party' => $fx->party->name,
                'voucher' => strtoupper($fx->voucher_type),
                'voucher_no' => $fx->voucher_no,
                'exchange' => $fx->exchange_rate,
                'base_debit' => $baseDebit ? $baseDebit . " ({$currBase})" : 0,
                'base_credit' => $baseCredit ? $baseCredit . " ({$currBase})" : 0,
                'local_debit' => $localDebit ? $localDebit . " ({$currLocal})" : 0,
                'local_credit' => $localCredit ? $localCredit . " ({$currLocal})" : 0,
                'avg_rate' => $fx->avg_rate,
                'closing_rate' => $closingRate,
                'diff' => $fx->avg_rate ? round($fx->avg_rate - $fx->exchange_rate, 4) : null,
                'realised' => $realised,
                'unrealised' => $unrealised,
            ];

            // optional virtual remaining row (show separate remaining if desired) for invoices
            if ($closingRate && (float)$fx->remaining_base_amount > 0 && in_array($fx->voucher_type, ['sale', 'purchase'])) {
                $rem = (float)$fx->remaining_base_amount;
                $rate = (float)$fx->exchange_rate;
                $diff = $closingRate - $rate;
                $unrealGL = round($diff * $rem, 2);

                if ($unrealGL >= 0) $totalUnrealisedGain += $unrealGL;
                else $totalUnrealisedLoss += abs($unrealGL);

                $rows[] = [
                    'sn' => $sn++,
                    'date' => $fx->transaction_date->format('d-m-Y'),
                    'party' => $fx->party->name,
                    'voucher' => strtoupper($fx->voucher_type) . ' (REMAINING)',
                    'voucher_no' => $fx->voucher_no,
                    'exchange' => $rate,
                    'base_debit' => 0,
                    'base_credit' => 0,
                    'local_debit' => 0,
                    'local_credit' => 0,
                    'avg_rate' => $closingRate,
                    'closing_rate' => $closingRate,
                    'diff' => round($diff, 4),
                    'realised' => 0,
                    'unrealised' => $unrealGL,
                ];
            }

            // optional: if this row is an advance (receipt/payment) and has remaining, show advance unreal in an extra row
            if ((in_array($fx->voucher_type, ['receipt', 'payment'])) && (float)$fx->remaining_base_amount > 0) {
                $rem = (float)$fx->remaining_base_amount;
                // compute avg invoice rate for this ledger type (same logic as FIFO service)
                if ($fx->ledger_type === 'customer') {
                    $agg = ForexRemittance::where([
                        'party_id' => $fx->party_id,
                        'ledger_type' => 'customer',
                        'base_currency_id' => $fx->base_currency_id,
                        'voucher_type' => 'sale'
                    ])->select(DB::raw('COALESCE(SUM(base_amount * exchange_rate),0) as weighted_sum'), DB::raw('COALESCE(SUM(base_amount),0) as total_base'))->first();

                    $avg = ($agg && $agg->total_base > 0) ? ($agg->weighted_sum / $agg->total_base) : null;
                } else {
                    $agg = ForexRemittance::where([
                        'party_id' => $fx->party_id,
                        'ledger_type' => 'supplier',
                        'base_currency_id' => $fx->base_currency_id,
                        'voucher_type' => 'purchase'
                    ])->select(DB::raw('COALESCE(SUM(base_amount * exchange_rate),0) as weighted_sum'), DB::raw('COALESCE(SUM(base_amount),0) as total_base'))->first();

                    $avg = ($agg && $agg->total_base > 0) ? ($agg->weighted_sum / $agg->total_base) : null;
                }

                if ($avg !== null) {
                    $advDiff = round(($avg - (float)$fx->exchange_rate) * $rem, 2);
                } else {
                    $advDiff = 0;
                }

                $rows[] = [
                    'sn' => $sn++,
                    'date' => $fx->transaction_date->format('d-m-Y'),
                    'party' => $fx->party->name,
                    'voucher' => strtoupper($fx->voucher_type) . ' (ADVANCE REMAINING)',
                    'voucher_no' => $fx->voucher_no,
                    'exchange' => $fx->exchange_rate,
                    'base_debit' => 0,
                    'base_credit' => 0,
                    'local_debit' => 0,
                    'local_credit' => 0,
                    'avg_rate' => $avg,
                    'closing_rate' => $closingRate,
                    'diff' => $avg ? round($avg - $fx->exchange_rate, 4) : null,
                    'realised' => 0,
                    'unrealised' => $advDiff,
                ];

                if ($advDiff >= 0) $totalUnrealisedGain += $advDiff;
                else $totalUnrealisedLoss += abs($advDiff);
            }
        }

        $finalNet = ($totalRealisedGain - $totalRealisedLoss) + ($totalUnrealisedGain - $totalUnrealisedLoss);

        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsTotal,
            'data' => $rows,
            'totals' => [
                'realised_gain' => $totalRealisedGain,
                'realised_loss' => $totalRealisedLoss,
                'unrealised_gain' => $totalUnrealisedGain,
                'unrealised_loss' => $totalUnrealisedLoss,
                'final_gain_loss' => $finalNet,
            ],
        ]);
    }



    /**
     * Party ledger UI
     */
    public function partyLedger($partyId)
    {
        $currency_list = Currency::active()->get();
        return view('backend.forex.party_ledger', compact('partyId', 'currency_list'));
    }
    public function partyLedgerData(Request $request, $partyId)
    {
        $currencyId = $request->currency_id;
        $start = $request->starting_date ?: date('Y-m-01');
        $end = $request->ending_date ?: date('Y-m-d');

        $q = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            ->where('party_id', $partyId)
            ->when($currencyId && $currencyId != 0, fn($q) => $q->where('base_currency_id', $currencyId))
            ->whereBetween('transaction_date', [$start . " 00:00:00", $end . " 23:59:59"])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        $rowsDB = $q->get();
        $data = [];
        $sn = 1;

        $runningBaseBalance = 0.0;
        $runningLocalBalance = 0.0;

        foreach ($rowsDB as $rawFx) {
            $fx = $rawFx->fresh();

            $baseDebit = $fx->direction == 'debit' ? (float)$fx->base_amount : 0;
            $baseCredit = $fx->direction == 'credit' ? (float)$fx->base_amount : 0;
            $localDebit = $fx->direction == 'debit' ? (float)$fx->local_amount : 0;
            $localCredit = $fx->direction == 'credit' ? (float)$fx->local_amount : 0;

            $runningBaseBalance += ($baseDebit - $baseCredit);
            $runningLocalBalance += ($localDebit - $localCredit);

            $realised = in_array($fx->voucher_type, ['sale', 'purchase']) ? ((float)$fx->realised_gain - (float)$fx->realised_loss) : "0";
            $unrealised = ((float)$fx->unrealised_gain || (float)$fx->unrealised_loss)
                ? ((float)$fx->unrealised_gain - (float)$fx->unrealised_loss)
                : (in_array($fx->voucher_type, ['sale', 'purchase']) ? 0 : "0");

            $data[] = [
                'sn' => $sn++,
                'date' => $fx->transaction_date->format('d-m-Y'),
                'particulars' => strtoupper($fx->voucher_type) . ' - ' . $fx->voucher_no,
                'base_debit' => $baseDebit ? $baseDebit . ' (' . ($fx->baseCurrency->code ?? '') . ')' : 0,
                'base_credit' => $baseCredit ? $baseCredit . ' (' . ($fx->baseCurrency->code ?? '') . ')' : 0,
                'local_debit' => $localDebit ? $localDebit . ' (' . ($fx->localCurrency->code ?? '') . ')' : 0,
                'local_credit' => $localCredit ? $localCredit . ' (' . ($fx->localCurrency->code ?? '') . ')' : 0,
                'realised' => $realised,
                'unrealised' => $unrealised,
                'running_base' => $runningBaseBalance,
                'running_local' => $runningLocalBalance,
            ];
        }

        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => count($data),
            'recordsFiltered' => count($data),
            'data' => $data
        ]);
    }
}
