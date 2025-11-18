<?php

namespace App\Http\Controllers;

use App\Models\ForexRemittance;
use App\Services\ForexFifoService;
use Illuminate\Http\Request;
use App\Models\Currency;

use Illuminate\Support\Facades\Log;

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
        Log::info("New Forex Entry", $validated);

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
        Log::info("Saving New Forex Remittance", $validated);
        $rem = ForexRemittance::create($validated);

        // 6. Apply FIFO
        Log::info("Triggering FIFO for Party={$rem->party_id}, Type={$rem->ledger_type}, Currency={$rem->base_currency_id}");
        $fifo->applyFifoFor(
            $rem->party_id,
            $rem->ledger_type,
            $rem->base_currency_id
        );

        return redirect()->back()->with('success', 'Forex Remittance Added Successfully');
    }




    public function forexRemittanceData(Request $request)
    {
        $partyType  = $request->party_type;
        $currencyId = $request->currency_id;
        $startDate  = $request->starting_date;
        $endDate    = $request->ending_date;

        // ---------------------------------------------------------------------
        // 1) BASE QUERY
        // ---------------------------------------------------------------------
        $baseQuery = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            // apply only when party_type is not empty
            ->when(!empty($partyType), function ($q) use ($partyType) {
                return $q->where('ledger_type', $partyType);
            })
            ->when($currencyId && $currencyId != 0, fn($q) => $q->where('base_currency_id', $currencyId))
            ->whereBetween('transaction_date', [
                $startDate . " 00:00:00",
                $endDate   . " 23:59:59"
            ])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        // Count original DB rows
        $totalDbRows = (clone $baseQuery)->count();

        // Pagination
        $limit = $request->length;
        $start = $request->start;

        $remittances = (clone $baseQuery)
            ->offset($start)
            ->limit($limit)
            ->get();

        $data = [];
        $sn = $start + 1;

        // ---------------------------------------------------------------------
        // TOTALS
        // ---------------------------------------------------------------------
        $totalRealisedGain   = 0;
        $totalRealisedLoss   = 0;
        $totalUnrealisedGain = 0;
        $totalUnrealisedLoss = 0;

        // ---------------------------------------------------------------------
        // 2) ORIGINAL ROWS
        // ---------------------------------------------------------------------
        foreach ($remittances as $fx) {

            $baseDebit  = $fx->direction == 'debit'  ? $fx->base_amount  : 0;
            $baseCredit = $fx->direction == 'credit' ? $fx->base_amount  : 0;

            $localDebit  = $fx->direction == 'debit'  ? $fx->local_amount : 0;
            $localCredit = $fx->direction == 'credit' ? $fx->local_amount : 0;

            $diff = $fx->avg_rate
                ? round($fx->avg_rate - $fx->exchange_rate, 4)
                : null;

            $realised   = $fx->realised_gain - $fx->realised_loss;
            $unrealised = $fx->unrealised_gain - $fx->unrealised_loss;

            // Add to totals
            $totalRealisedGain   += $fx->realised_gain;
            $totalRealisedLoss   += $fx->realised_loss;
            $totalUnrealisedGain += $fx->unrealised_gain;
            $totalUnrealisedLoss += $fx->unrealised_loss;

            // Build row
            $data[] = [
                'sn'          => $sn++,
                'date'        => $fx->transaction_date->format('d-m-Y'),
                'particulars' => $fx->party->name,
                'vch_type'    => strtoupper($fx->voucher_type),
                'vch_no'      => $fx->voucher_no,
                'exch_rate'   => $fx->exchange_rate,

                'base_debit'  => $baseDebit
                    ? $baseDebit . " (" . $fx->baseCurrency->code . ")"
                    : 0,

                'base_credit' => $baseCredit
                    ? $baseCredit . " (" . $fx->baseCurrency->code . ")"
                    : 0,

                'local_debit' => $localDebit
                    ? $localDebit . " (" . $fx->localCurrency->code . ")"
                    : 0,

                'local_credit' => $localCredit
                    ? $localCredit . " (" . $fx->localCurrency->code . ")"
                    : 0,

                'closing_rate' => null,
                'avg_rate'      => $fx->avg_rate,
                'diff'          => $diff,
                'realised'      => $realised,
                'unrealised'    => $unrealised,
                'remarks'       => $fx->remarks,
            ];
        }

        // ---------------------------------------------------------------------
        // 3) AUTO DETECT CLOSING RATE (if user did not provide)
        // ---------------------------------------------------------------------
        if ($request->filled('closing_rate_global')) {
            $closingRate = (float)$request->closing_rate_global;
        } else {
            // Latest exchange rate from filtered rows
            $closingRate = (clone $baseQuery)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->value('exchange_rate');

            // fallback to currency table
            if (!$closingRate && $currencyId && $currencyId != 0) {
                $closingRate = optional(\App\Models\Currency::find($currencyId))->exchange_rate;
            }
        }

        // ---------------------------------------------------------------------
        // 4) ADD VIRTUAL UNSETTLED ROWS (UNREALISED)
        // ---------------------------------------------------------------------
        if ($closingRate) {
            foreach ($remittances as $fx) {

                if ($fx->remaining_base_amount > 0) {

                    $rem  = (float)$fx->remaining_base_amount;
                    $rate = (float)$fx->exchange_rate;

                    $diff   = $closingRate - $rate;
                    $unreal = round($diff * $rem, 2);

                    // Totals update
                    if ($unreal >= 0) {
                        $totalUnrealisedGain += $unreal;
                    } else {
                        $totalUnrealisedLoss += abs($unreal);
                    }

                    // virtual row
                    $data[] = [
                        'sn'          => $sn++,
                        'date'        => $fx->transaction_date->format('d-m-Y'),
                        'particulars' => $fx->party->name,
                        'vch_type'    => strtoupper($fx->voucher_type) . " (REMAINING)",
                        'vch_no'      => $fx->voucher_no,
                        'exch_rate'   => $rate,

                        'base_debit'   => 0,
                        'base_credit'  => 0,
                        'local_debit'  => 0,
                        'local_credit' => 0,

                        'closing_rate' => $closingRate,
                        'avg_rate'      => $closingRate,
                        'diff'          => round($diff, 4),
                        'realised'      => 0,
                        'unrealised'    => $unreal,
                        'remarks'       => "Unsettled Portion → Base $rem",
                    ];
                }
            }
        }

        // ---------------------------------------------------------------------
        // 5) TOTALS
        // ---------------------------------------------------------------------
        $finalNet = ($totalRealisedGain - $totalRealisedLoss)
            + ($totalUnrealisedGain - $totalUnrealisedLoss);

        return response()->json([
            'draw'            => intval($request->draw),
            'recordsTotal'    => count($data),   // real rows including virtual
            'recordsFiltered' => count($data),
            'data'            => $data,

            'totals' => [
                'realised_gain'    => $totalRealisedGain,
                'realised_loss'    => $totalRealisedLoss,
                'unrealised_gain'  => $totalUnrealisedGain,
                'unrealised_loss'  => $totalUnrealisedLoss,
                'final_gain_loss'  => $finalNet,
            ]
        ]);
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
        $type     = $request->type;
        $start    = $request->starting_date;
        $end      = $request->ending_date;
        $currency = $request->currency_id;

        // ---------------------------------------
        // BASE QUERY
        // ---------------------------------------
        $q = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            ->whereBetween('transaction_date', [
                $start . " 00:00:00",
                $end   . " 23:59:59",
            ])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        // Currency filter
        if (!empty($currency) && $currency != 0) {
            $q->where('base_currency_id', $currency);
        }

        // ---------------------------------------
        // TYPE FILTERS
        // ---------------------------------------
        switch ($type) {

            case 'invoice':
                $q->whereIn('voucher_type', ['sale', 'purchase']);
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

                // party, base, local → no extra filter
        }

        $recordsTotal = $q->count();

        // Pagination
        $rowsDB = $q->offset($request->start)
            ->limit($request->length)
            ->get();

        $rows = [];
        $sn   = $request->start + 1;

        // ---------------------------------------
        // TOTALS
        // ---------------------------------------
        $totalRealisedGain   = 0;
        $totalRealisedLoss   = 0;
        $totalUnrealisedGain = 0;
        $totalUnrealisedLoss = 0;

        // ---------------------------------------
        // FETCH CLOSING RATE
        // ---------------------------------------
        if ($request->filled('closing_rate_global')) {

            $closingRate = (float) $request->closing_rate_global;
        } else {

            // last used exchange_rate
            $closingRate = (clone $q)->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->value('exchange_rate');

            // fallback from currency table
            if (!$closingRate && $currency && $currency != 0) {
                $closingRate = optional(Currency::find($currency))->exchange_rate;
            }
        }

        // ---------------------------------------
        // BUILD DB ROWS + UNSETTLED VIRTUAL ROWS
        // ---------------------------------------
        foreach ($rowsDB as $fx) {

            $currBase  = $fx->baseCurrency->code  ?? '';
            $currLocal = $fx->localCurrency->code ?? '';

            $realised   = $fx->realised_gain - $fx->realised_loss;
            $unrealised = $fx->unrealised_gain - $fx->unrealised_loss;

            $totalRealisedGain   += $fx->realised_gain;
            $totalRealisedLoss   += $fx->realised_loss;
            $totalUnrealisedGain += $fx->unrealised_gain;
            $totalUnrealisedLoss += $fx->unrealised_loss;

            // DB Row
            $rows[] = [
                'sn'         => $sn++,
                'date'       => $fx->transaction_date->format('d-m-Y'),
                'party'      => $fx->party->name,
                'voucher'    => strtoupper($fx->voucher_type),
                'voucher_no' => $fx->voucher_no,
                'exchange'   => $fx->exchange_rate,

                'base_debit'  => $fx->direction == 'debit'  ? $fx->base_amount  . " ($currBase)"  : 0,
                'base_credit' => $fx->direction == 'credit' ? $fx->base_amount  . " ($currBase)"  : 0,
                'local_debit' => $fx->direction == 'debit'  ? $fx->local_amount . " ($currLocal)" : 0,
                'local_credit' => $fx->direction == 'credit' ? $fx->local_amount . " ($currLocal)" : 0,

                'avg_rate'   => $fx->avg_rate,
                'diff'       => $fx->avg_rate ? round($fx->avg_rate - $fx->exchange_rate, 4) : null,
                'realised'   => $realised,
                'unrealised' => $unrealised,
            ];

            // ---------------------------------------
            // VIRTUAL ROW FOR REMAINING (UNREALISED)
            // ---------------------------------------
            if ($closingRate && $fx->remaining_base_amount > 0) {

                $rem      = $fx->remaining_base_amount;
                $rate     = $fx->exchange_rate;
                $diff     = $closingRate - $rate;
                $unrealGL = round($diff * $rem, 2);

                // Add to totals
                if ($unrealGL >= 0) $totalUnrealisedGain += $unrealGL;
                else                $totalUnrealisedLoss += abs($unrealGL);

                $rows[] = [
                    'sn'         => $sn++,
                    'date'       => $fx->transaction_date->format('d-m-Y'),
                    'party'      => $fx->party->name,
                    'voucher'    => strtoupper($fx->voucher_type) . " (REMAINING)",
                    'voucher_no' => $fx->voucher_no,
                    'exchange'   => $rate,

                    'base_debit'   => 0,
                    'base_credit'  => 0,
                    'local_debit'  => 0,
                    'local_credit' => 0,

                    'avg_rate'  => $closingRate,
                    'diff'      => round($diff, 4),
                    'realised'  => 0,
                    'unrealised' => $unrealGL,
                ];
            }
        }

        return response()->json([
            'draw'            => intval($request->draw),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsTotal,
            'data'            => $rows,
        ]);
    }
}
