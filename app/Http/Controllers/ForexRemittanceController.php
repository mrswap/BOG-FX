<?php

namespace App\Http\Controllers;

use App\Models\ForexRemittance;
use App\Services\ForexFifoService;
use Illuminate\Http\Request;

class ForexRemittanceController extends Controller
{
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

        // 1. Auto ledger_type
        $validated['ledger_type'] = match ($validated['voucher_type']) {
            'sale', 'receipt'      => 'customer',
            'purchase', 'payment'  => 'supplier',
        };

        // 2. Auto direction
        $validated['direction'] = match ($validated['voucher_type']) {
            'sale', 'purchase' => 'debit',
            'payment', 'receipt' => 'credit',
        };

        // 3. Compute local amount
        $validated['local_amount'] = round($validated['base_amount'] * $validated['exchange_rate'], 4);

        // 4. FIFO fields
        $validated['remaining_base_amount'] = $validated['base_amount'];
        $validated['settled_base_amount']   = 0;

        // 5. Save remittance
        $rem = ForexRemittance::create($validated);

        // 6. Apply FIFO after saving
        $fifo->applyFifoFor(
            $rem->party_id,
            $rem->ledger_type,
            $rem->base_currency_id
        );

        return redirect()->back()->with('success', 'Forex Remittance Added Successfully');
    }



    public function forexRemittanceData(Request $request)
    {
        $columns = [
            0 => 'id',
            1 => 'transaction_date',
            2 => 'party_id',
            3 => 'voucher_type',
            4 => 'voucher_no',
            5 => 'exchange_rate',
        ];

        $partyType  = $request->party_type;      // customer / supplier
        $currencyId = $request->currency_id;     // base_currency_id
        $startDate  = $request->starting_date;
        $endDate    = $request->ending_date;

        // ============================================================
        // 🔥 1. QUERY (with all filters)
        // ============================================================

        $query = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            ->when($partyType, function ($q) use ($partyType) {
                $q->where('ledger_type', $partyType);
            })
            ->when($currencyId && $currencyId != 0, function ($q) use ($currencyId) {
                $q->where('base_currency_id', $currencyId);
            })
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->orderBy('transaction_date', 'asc');

        $totalData = $query->count();

        // Paging
        $limit = $request->length;
        $start = $request->start;

        // ============================================================
        // 🔥 2. Fetch Paginated Rows
        // ============================================================

        $remittances = $query
            ->offset($start)
            ->limit($limit)
            ->get();

        $data = [];
        $sn = $start + 1;

        // SUM TOTALS
        $totalRealisedGain = 0;
        $totalRealisedLoss = 0;
        $totalUnrealisedGain = 0;
        $totalUnrealisedLoss = 0;

        foreach ($remittances as $fx) {

            // Base Debit / Credit
            $baseDebit  = $fx->direction == 'debit'  ? $fx->base_amount  : 0;
            $baseCredit = $fx->direction == 'credit' ? $fx->base_amount  : 0;

            // Local Debit / Credit
            $localDebit  = $fx->direction == 'debit'  ? $fx->local_amount : 0;
            $localCredit = $fx->direction == 'credit' ? $fx->local_amount : 0;

            // Diff column (avg_rate - exchange_rate)
            $diff = null;
            if ($fx->avg_rate) {
                $diff = round(($fx->avg_rate - $fx->exchange_rate), 4);
            }

            // Realised (net)
            $realised = ($fx->realised_gain - $fx->realised_loss);

            // Unrealised (net)
            $unrealised = ($fx->unrealised_gain - $fx->unrealised_loss);

            // Add to SUMMARY TOTALS
            $totalRealisedGain     += $fx->realised_gain;
            $totalRealisedLoss     += $fx->realised_loss;
            $totalUnrealisedGain   += $fx->unrealised_gain;
            $totalUnrealisedLoss   += $fx->unrealised_loss;

            // ================================
            // 🔥 FORMAT ROW FOR DATATABLE
            // ================================

            $nestedData = [
                'sn'          => $sn++,
                'date'        => $fx->transaction_date->format('d-m-Y'),
                'particulars' => $fx->party->name,
                'vch_type'    => strtoupper($fx->voucher_type),
                'vch_no'      => $fx->voucher_no,
                'exch_rate'   => $fx->exchange_rate,

                'base_debit'  => $baseDebit,
                'base_credit' => $baseCredit,

                'local_debit'  => $localDebit,
                'local_credit' => $localCredit,

                'avg_rate'    => $fx->avg_rate,
                'diff'        => $diff,

                'realised'    => $realised,
                'unrealised'  => $unrealised,

                'remarks'     => $fx->remarks,
            ];

            $data[] = $nestedData;
        }

        // FINAL GAIN LOSS = realised + unrealised
        $finalNet = ($totalRealisedGain - $totalRealisedLoss)
            + ($totalUnrealisedGain - $totalUnrealisedLoss);

        // ============================================================
        // 🔥 3. DATATABLE JSON OUTPUT
        // ============================================================

        $json_data = [
            "draw"            => intval($request->draw),
            "recordsTotal"    => intval($totalData),
            "recordsFiltered" => intval($totalData),
            "data"            => $data,

            "totals" => [
                "realised_gain"      => $totalRealisedGain,
                "realised_loss"      => $totalRealisedLoss,
                "unrealised_gain"    => $totalUnrealisedGain,
                "unrealised_loss"    => $totalUnrealisedLoss,
                "final_gain_loss"    => $finalNet,
            ]
        ];

        return response()->json($json_data);
    }


    public function report(Request $request, $type)
    {
        $valid = ['invoice', 'party', 'base', 'local', 'realised', 'unrealised'];

        if (!in_array($type, $valid)) {
            abort(404);
        }

        // currency list for filters
        $currency_list = \App\Models\Currency::active()->get();

        return view('backend.forex_reports.unified', compact('type', 'currency_list'));
    }

    public function reportData(Request $request)
    {
        $type = $request->type;     // invoice, party, base, local, realised, unrealised
        $start = $request->starting_date;
        $end   = $request->ending_date;
        $currency = $request->currency_id;

        // base query
        $q = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            ->whereBetween('transaction_date', [$start, $end]);

        if ($currency && $currency != 0) {
            $q->where('base_currency_id', $currency);
        }

        // ==================================
        // 🔥 REPORT BASED QUERY FILTERS
        // ==================================

        switch ($type) {
            case 'invoice':
                $q->whereIn('voucher_type', ['sale', 'purchase']);
                break;

            case 'party':
                // no additional filter, display party grouping
                break;

            case 'base':
                // group by base currency
                break;

            case 'local':
                // group by local currency
                break;

            case 'realised':
                $q->where(function ($z) {
                    $z->where('realised_gain', '>', 0)
                        ->orWhere('realised_loss', '>', 0);
                });
                break;

            case 'unrealised':
                $q->where(function ($z) {
                    $z->where('unrealised_gain', '>', 0)
                        ->orWhere('unrealised_loss', '>', 0);
                });
                break;
        }

        $recordsTotal = $q->count();

        // paginate
        $data = $q->orderBy('transaction_date')
            ->offset($request->start)
            ->limit($request->length)
            ->get();

        // ==================================
        // 🔥 FORMAT OUTPUT BASED ON REPORT TYPE
        // ==================================

        $rows = [];
        $sn = $request->start + 1;

        foreach ($data as $fx) {

            $baseDebit  = $fx->direction == 'debit' ? $fx->base_amount : 0;
            $baseCredit = $fx->direction == 'credit' ? $fx->base_amount : 0;
            $localDebit = $fx->direction == 'debit' ? $fx->local_amount : 0;
            $localCredit = $fx->direction == 'credit' ? $fx->local_amount : 0;

            $diff = $fx->avg_rate ? ($fx->avg_rate - $fx->exchange_rate) : null;

            $realised = $fx->realised_gain - $fx->realised_loss;
            $unrealised = $fx->unrealised_gain - $fx->unrealised_loss;

            $rows[] = [
                'sn'         => $sn++,
                'date'       => $fx->transaction_date->format('d-m-Y'),
                'party'      => $fx->party->name,
                'voucher'    => strtoupper($fx->voucher_type),
                'voucher_no' => $fx->voucher_no,
                'exchange'   => $fx->exchange_rate,

                'base_debit' => $baseDebit,
                'base_credit' => $baseCredit,
                'local_debit' => $localDebit,
                'local_credit' => $localCredit,

                'avg_rate'   => $fx->avg_rate,
                'diff'       => $diff,
                'realised'   => $realised,
                'unrealised' => $unrealised,
            ];
        }

        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsTotal,
            'data' => $rows,
        ]);
    }
}
