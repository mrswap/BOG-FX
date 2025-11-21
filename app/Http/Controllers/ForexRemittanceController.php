<?php

namespace App\Http\Controllers;

use App\Models\ForexRemittance;
use App\Models\Currency;
use App\Services\ForexFifoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Party;

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

    public function store(Request $request, ForexFifoService $fifo)
    {
        $validated = $request->validate([
            'party_id'         => 'required|integer',
            'transaction_date' => 'required|date',
            'voucher_type'     => 'required|string',
            'voucher_no'       => 'required|string',
            'base_currency_id' => 'required|integer',
            'local_currency_id' => 'required|integer',
            'base_amount'      => 'required|numeric',
            'exchange_rate'    => 'required|numeric',
            'avg_rate'         => 'nullable|numeric',
            'closing_rate'     => 'nullable|numeric',
            'remarks'          => 'nullable|string',
        ]);

        Log::info("New Forex Entry", $validated);
        $validated['voucher_type'] = strtolower($validated['voucher_type']);

        // Ledger mapping
        $validated['ledger_type'] = match ($validated['voucher_type']) {
            'sale', 'receipt'   => 'customer',
            'purchase', 'payment' => 'supplier',
        };

        // Debit/Credit direction
        $validated['direction'] = match ($validated['voucher_type']) {
            'sale', 'payment'      => 'debit',
            'receipt', 'purchase'  => 'credit',
        };

        // Derived fields
        $validated['local_amount'] = round($validated['base_amount'] * $validated['exchange_rate'], 4);

        // FIFO initial fields
        $validated['remaining_base_amount'] = $validated['base_amount'];
        $validated['settled_base_amount'] = 0;

        // Realised always 0 on creation
        $validated['realised_gain'] = 0;
        $validated['realised_loss'] = 0;

        // ❌ Do NOT assign unrealised here (FIFO will compute it correctly)
        // $validated['unrealised_gain'] = 0;
        // $validated['unrealised_loss'] = 0;

        Log::info("Saving New Forex Remittance", $validated);
        $rem = ForexRemittance::create($validated);

        // Run FIFO settlement logic
        Log::info("Triggering FIFO for Party={$rem->party_id}, Type={$rem->ledger_type}, Currency={$rem->base_currency_id}");
        $fifo->applyFifoFor($rem->party_id, $rem->ledger_type, $rem->base_currency_id);

        return redirect()->back()->with('success', 'Forex Remittance Added Successfully');
    }

    public function forexRemittanceData(Request $request)
    {
        $partyType  = $request->party_type;
        $currencyId = $request->currency_id;
        $startDate  = $request->starting_date ?: date('Y-m-01');
        $endDate    = $request->ending_date ?: date('Y-m-d');

        $baseQuery = ForexRemittance::when(!empty($partyType), fn($q) => $q->where('ledger_type', $partyType))
            ->when($currencyId && $currencyId != 0, fn($q) => $q->where('base_currency_id', $currencyId))
            ->whereBetween('transaction_date', [$startDate . " 00:00:00", $endDate . " 23:59:59"])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        $limit = (int) $request->length;
        $start = (int) $request->start;

        $rowsDB = ForexRemittance::whereIn(
            'id',
            (clone $baseQuery)->offset($start)->limit($limit)->pluck('id')
        )
            ->with('party', 'baseCurrency', 'localCurrency')
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $data = [];
        $sn = $start + 1;

        $totalRealisedGain = 0;
        $totalRealisedLoss = 0;
        $totalUnrealisedGain = 0;
        $totalUnrealisedLoss = 0;

        // Closing rate for UI display only
        if ($request->filled('closing_rate_global')) {
            $closingRate = (float)$request->closing_rate_global;
        } else {
            $closingRate = (clone $baseQuery)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->value('exchange_rate');

            if (!$closingRate && $currencyId && $currencyId != 0) {
                $closingRate = optional(Currency::find($currencyId))->exchange_rate;
            }
        }

        foreach ($rowsDB as $rawFx) {

            // fetch latest values after FIFO updates
            $fx = $rawFx->fresh();

            $currBase  = $fx->baseCurrency->code ?? '';
            $currLocal = $fx->localCurrency->code ?? '';

            $baseDebit  = $fx->direction == 'debit' ? $fx->base_amount : 0;
            $baseCredit = $fx->direction == 'credit' ? $fx->base_amount : 0;

            $localDebit  = $fx->direction == 'debit' ? $fx->local_amount : 0;
            $localCredit = $fx->direction == 'credit' ? $fx->local_amount : 0;

            // ****************************************************************
            //  HANDLE INVOICE ROWS: SALE / PURCHASE (Debit Rows)
            // ****************************************************************
            if (in_array($fx->voucher_type, ['sale', 'purchase'])) {

                $realisedVal = $fx->realised_gain - $fx->realised_loss;
                $unrealisedVal = $fx->unrealised_gain - $fx->unrealised_loss;

                $totalRealisedGain += $fx->realised_gain;
                $totalRealisedLoss += $fx->realised_loss;
                $totalUnrealisedGain += $fx->unrealised_gain;
                $totalUnrealisedLoss += $fx->unrealised_loss;

                $remarks = ($fx->remaining_base_amount > 0)
                    ? "Remaining Base: {$fx->remaining_base_amount}"
                    : null;
            }

            // ****************************************************************
            //  HANDLE CREDIT ROWS: RECEIPT / PAYMENT REMAINING BASE
            // ****************************************************************
            else if ($fx->direction == 'credit') {

                if ($fx->remaining_base_amount > 0) {

                    $remaining = (float)$fx->remaining_base_amount;

                    // Standard Accounting Unrealised:
                    // unreal = (closing rate – credit rate) × remaining base
                    $rateDiff = $closingRate - (float)$fx->exchange_rate;
                    $unrealisedVal = round($rateDiff * $remaining, 2);

                    if ($unrealisedVal >= 0) {
                        $totalUnrealisedGain += $unrealisedVal;
                    } else {
                        $totalUnrealisedLoss += abs($unrealisedVal);
                    }

                    $remarks = "Remaining Base: {$remaining}";
                    $realisedVal = "";
                } else {
                    $realisedVal = "";
                    $unrealisedVal = "";
                    $remarks = "";
                }
            }

            // ****************************************************************
            //  Build row data
            // ****************************************************************
            $data[] = [
                'sn' => $sn++,
                'date' => $fx->transaction_date->format('d-m-Y'),
                'particulars' => $fx->party->name,
                'vch_type' => strtoupper($fx->voucher_type),
                'vch_no' => $fx->voucher_no,
                'exch_rate' => $fx->exchange_rate,

                'base_debit' => $baseDebit ? "{$baseDebit} ({$currBase})" : 0,
                'base_credit' => $baseCredit ? "{$baseCredit} ({$currBase})" : 0,
                'local_debit' => $localDebit ? "{$localDebit} ({$currLocal})" : 0,
                'local_credit' => $localCredit ? "{$localCredit} ({$currLocal})" : 0,

                'avg_rate' => $fx->avg_rate,
                'closing_rate' => $closingRate,
                'diff' => $fx->avg_rate ? round($fx->avg_rate - $fx->exchange_rate, 4) : null,

                'realised' => $realisedVal,
                'unrealised' => $unrealisedVal,
                'remarks' => $remarks,
            ];
        }

        $finalNet = ($totalRealisedGain - $totalRealisedLoss)
            + ($totalUnrealisedGain - $totalUnrealisedLoss);

        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => (clone $baseQuery)->count(),
            'recordsFiltered' => (clone $baseQuery)->count(),
            'data' => $data,
            'totals' => [
                'realised_gain' => $totalRealisedGain,
                'realised_loss' => $totalRealisedLoss,
                'unrealised_gain' => $totalUnrealisedGain,
                'unrealised_loss' => $totalUnrealisedLoss,
                'final_gain_loss' => $finalNet,
            ]
        ]);
    }




    public function report(Request $request, $type)
    {
        $valid = ['invoice', 'party', 'base', 'local', 'realised', 'unrealised'];
        if (!in_array($type, $valid)) abort(404);

        // default date range (month to date)
        $starting_date = $request->starting_date ?? date('Y-m-01');
        $ending_date   = $request->ending_date   ?? date('Y-m-d');

        // load filter data
        $currency_list = Currency::active()->get();
        $party_list    = Party::orderBy('name')->get();

        // voucher list limited to the date range to keep select small
        $voucher_list = ForexRemittance::whereBetween('transaction_date', [$starting_date . ' 00:00:00', $ending_date . ' 23:59:59'])
            ->orderBy('voucher_no')
            ->distinct()
            ->pluck('voucher_no')
            ->filter() // remove nulls
            ->values();

        return view('backend.forex_reports.unified', compact(
            'type',
            'currency_list',
            'party_list',
            'voucher_list',
            'starting_date',
            'ending_date'
        ));
    }


    public function reportData(Request $request)
    {
        // inputs
        $type = $request->type; // invoice, party, base, local, realised, unrealised
        $start = $request->starting_date ?: date('Y-m-01');
        $end   = $request->ending_date   ?: date('Y-m-d');
        $currency = $request->currency_id;        // base currency filter
        $party    = $request->party_id;           // party filter
        $voucher  = $request->voucher_no;         // voucher filter
        $closingRateInput = $request->closing_rate_global; // optional

        // base query (scoped to date range)
        $q = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            ->whereBetween('transaction_date', [$start . " 00:00:00", $end . " 23:59:59"])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        if (!empty($currency) && $currency != 0) $q->where('base_currency_id', $currency);
        if (!empty($party)) $q->where('party_id', $party);
        if (!empty($voucher)) $q->where('voucher_no', $voucher);

        // type-specific filters
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
                // party/base/local — no extra where; aggregated UI will use the same rows
        }

        $recordsTotal = $q->count();

        // pagination for DataTables
        $startRow = (int)$request->start;
        $length = (int)$request->length ?: 100;

        $rowsDB = (clone $q)->offset($startRow)->limit($length)->get();

        // -- determine closing rate priority (avg > closing > exchange) for first unsettled only
        // we will compute a single closing_rate for virtual rows:
        if ($request->filled('closing_rate_global')) {
            $closingRate = (float)$closingRateInput;
        } else {
            // prefer last exchange_rate in filtered query
            $closingRate = (clone $q)->orderBy('transaction_date', 'desc')->orderBy('id', 'desc')->value('exchange_rate');
            // fallback to currency master
            if (!$closingRate && !empty($currency) && $currency != 0) {
                $closingRate = optional(Currency::find($currency))->exchange_rate;
            }
        }

        $rows = [];
        $sn = $startRow + 1;

        // totals
        $totalRealisedGain = 0.0;
        $totalRealisedLoss = 0.0;
        $totalUnrealisedGain = 0.0;
        $totalUnrealisedLoss = 0.0;

        foreach ($rowsDB as $fx) {
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
                'closing_rate' => null,
                'diff' => $fx->avg_rate ? round($fx->avg_rate - $fx->exchange_rate, 4) : null,
                'realised' => $realised,
                'unrealised' => $unrealised,
            ];

            // virtual remaining row (unrealised) — uses closingRate detected above
            if ($closingRate && (float)$fx->remaining_base_amount > 0) {
                $rem = (float)$fx->remaining_base_amount;
                // For first unsettled logic the priority is: avg_rate > closing_rate field > exchange_rate.
                // But since this is a generic report we show closingRate as chosen above
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

    /**
     * Party ledger DataTables API
     */
    public function partyLedgerData(Request $request, $partyId)
    {
        // reuse baseQuery but scoped to partyId
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

        $runningBaseBalance = 0.0; // in base currency basis (net)
        $runningLocalBalance = 0.0;

        foreach ($rowsDB as $fx) {
            $baseDebit = $fx->direction == 'debit' ? $fx->base_amount : 0;
            $baseCredit = $fx->direction == 'credit' ? $fx->base_amount : 0;
            $localDebit = $fx->direction == 'debit' ? $fx->local_amount : 0;
            $localCredit = $fx->direction == 'credit' ? $fx->local_amount : 0;

            // running balances (base and local) — you can choose sign/position like Tally (DR/CR)
            $runningBaseBalance += ($baseDebit - $baseCredit);
            $runningLocalBalance += ($localDebit - $localCredit);

            $data[] = [
                'sn' => $sn++,
                'date' => $fx->transaction_date->format('d-m-Y'),
                'particulars' => strtoupper($fx->voucher_type) . ' - ' . $fx->voucher_no,
                'base_debit' => $baseDebit ? $baseDebit . ' (' . ($fx->baseCurrency->code ?? '') . ')' : 0,
                'base_credit' => $baseCredit ? $baseCredit . ' (' . ($fx->baseCurrency->code ?? '') . ')' : 0,
                'local_debit' => $localDebit ? $localDebit . ' (' . ($fx->localCurrency->code ?? '') . ')' : 0,
                'local_credit' => $localCredit ? $localCredit . ' (' . ($fx->localCurrency->code ?? '') . ')' : 0,
                'realised' => $fx->realised_gain - $fx->realised_loss,
                'unrealised' => $fx->unrealised_gain - $fx->unrealised_loss,
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
