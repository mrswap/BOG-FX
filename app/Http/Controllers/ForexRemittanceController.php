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

        // FIX: Default pagination
        $limit = $request->length ?? 10;
        $start = $request->start ?? 0;

        // BASE QUERY
        $baseQuery = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            ->when(!empty($partyType), fn($q) => $q->where('ledger_type', $partyType))
            ->when($currencyId && $currencyId != 0, fn($q) => $q->where('base_currency_id', $currencyId))
            ->whereBetween('transaction_date', [
                $startDate . " 00:00:00",
                $endDate   . " 23:59:59"
            ])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        $totalDbRows = (clone $baseQuery)->count();

        $remittances = (clone $baseQuery)
            ->offset($start)
            ->limit($limit)
            ->get();

        $data = [];
        $sn = $start + 1;

        $totalRealisedGain = $totalRealisedLoss = 0;
        $totalUnrealisedGain = $totalUnrealisedLoss = 0;

        foreach ($remittances as $fx) {

            $baseDebit  = $fx->direction == 'debit'  ? $fx->base_amount  : 0;
            $baseCredit = $fx->direction == 'credit' ? $fx->base_amount  : 0;
            $localDebit = $fx->direction == 'debit'  ? $fx->local_amount : 0;
            $localCredit = $fx->direction == 'credit' ? $fx->local_amount : 0;

            $diff = $fx->avg_rate ? round($fx->avg_rate - $fx->exchange_rate, 4) : null;

            $realised   = $fx->realised_gain - $fx->realised_loss;
            $unrealised = $fx->unrealised_gain - $fx->unrealised_loss;

            $totalRealisedGain   += $fx->realised_gain;
            $totalRealisedLoss   += $fx->realised_loss;
            $totalUnrealisedGain += $fx->unrealised_gain;
            $totalUnrealisedLoss += $fx->unrealised_loss;

            $data[] = [
                'sn'          => $sn++,
                'date'        => $fx->transaction_date->format('d-m-Y'),
                'particulars' => $fx->party->name,
                'vch_type'    => strtoupper($fx->voucher_type),
                'vch_no'      => $fx->voucher_no,
                'exch_rate'   => $fx->exchange_rate,

                'base_debit'  => $baseDebit ? $baseDebit . " ({$fx->baseCurrency->code})" : 0,
                'base_credit' => $baseCredit ? $baseCredit . " ({$fx->baseCurrency->code})" : 0,

                'local_debit' => $localDebit ? $localDebit . " ({$fx->localCurrency->code})" : 0,
                'local_credit' => $localCredit ? $localCredit . " ({$fx->localCurrency->code})" : 0,

                'closing_rate' => null,
                'avg_rate'     => $fx->avg_rate,
                'diff'         => $diff,
                'realised'     => $realised,
                'unrealised'   => $unrealised,
                'remarks'      => $fx->remarks,
            ];
        }

        // AUTO-DETECT CLOSING RATE
        if ($request->filled('closing_rate_global')) {
            $closingRate = (float) $request->closing_rate_global;
        } else {
            $closingRate = (clone $baseQuery)
                ->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->value('exchange_rate');

            if (!$closingRate && $currencyId) {
                $closingRate = optional(Currency::find($currencyId))->exchange_rate;
            }
        }

        // VIRTUAL ROWS FOR UNSETTLED
        if ($closingRate) {
            foreach ($remittances as $fx) {
                if ($fx->remaining_base_amount > 0) {

                    $rem  = $fx->remaining_base_amount;
                    $rate = $fx->exchange_rate;

                    $diff = $closingRate - $rate;
                    $unreal = round($diff * $rem, 2);

                    if ($unreal >= 0) $totalUnrealisedGain += $unreal;
                    else $totalUnrealisedLoss += abs($unreal);

                    $data[] = [
                        'sn'          => $sn++,
                        'date'        => $fx->transaction_date->format('d-m-Y'),
                        'particulars' => $fx->party->name,
                        'vch_type'    => strtoupper($fx->voucher_type) . " (REMAINING)",
                        'vch_no'      => $fx->voucher_no,
                        'exch_rate'   => $rate,

                        'base_debit'  => 0,
                        'base_credit' => 0,
                        'local_debit' => 0,
                        'local_credit' => 0,

                        'closing_rate' => $closingRate,
                        'avg_rate'    => $closingRate,
                        'diff'        => round($diff, 4),
                        'realised'    => 0,
                        'unrealised'  => $unreal,
                        'remarks'     => "Unsettled Portion → Base $rem",
                    ];
                }
            }
        }

        $finalNet = ($totalRealisedGain - $totalRealisedLoss)
            + ($totalUnrealisedGain - $totalUnrealisedLoss);

        return response()->json([
            'draw'            => intval($request->draw),
            'recordsTotal'    => count($data),
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
        $type     = $request->type;   // invoice, party, base, local, realised, unrealised
        $start    = $request->starting_date;
        $end      = $request->ending_date;
        $currency = $request->currency_id;

        // ------------------------------------------------
        // 1) MAIN QUERY (inside range)
        // ------------------------------------------------
        $q = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            ->whereBetween('transaction_date', [
                $start . " 00:00:00",
                $end   . " 23:59:59",
            ])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        if (!empty($currency) && $currency != 0) {
            $q->where('base_currency_id', $currency);
        }

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

                // party, base, local → no extra filter (simple listing)
        }

        $recordsTotal = $q->count();

        // Pagination
        $rowsDB = $q->offset($request->start)
            ->limit($request->length)
            ->get();

        // ------------------------------------------------
        // 2) CLOSING RATE for this report
        // ------------------------------------------------
        if ($request->filled('closing_rate_global')) {
            $closingRate = (float) $request->closing_rate_global;
        } else {
            $closingRate = (clone $q)->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->value('exchange_rate');

            if (!$closingRate && $currency && $currency != 0) {
                $closingRate = optional(Currency::find($currency))->exchange_rate;
            }
        }

        // ------------------------------------------------
        // 3) OPENING BALANCE (before $start)
        // ------------------------------------------------
        $openingRows = ForexRemittance::with('party', 'baseCurrency', 'localCurrency')
            ->where('transaction_date', '<', $start . " 00:00:00")
            ->when($currency && $currency != 0, fn($z) => $z->where('base_currency_id', $currency))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $openingBaseNet  = 0.0;  // debit - credit
        $openingLocalNet = 0.0;

        // We’ll also compute unrealised of opening with same logic as ledger
        $openingUnrealised = 0.0;

        // key-wise state (per direction)
        $openState = [];

        foreach ($openingRows as $fx) {

            $baseSign  = $fx->direction === 'debit' ? +1 : -1;
            $localSign = $baseSign;

            $openingBaseNet  += $baseSign  * (float)$fx->base_amount;
            $openingLocalNet += $localSign * (float)$fx->local_amount;

            $rem = (float)$fx->remaining_base_amount;
            if ($rem <= 0) continue;

            $key = $fx->party_id . '|' . $fx->ledger_type . '|' . $fx->base_currency_id . '|' . $fx->direction;

            $isFirstOpen = !isset($openState[$key]) || $openState[$key]['open_base'] <= 0;

            if ($isFirstOpen) {
                if (!is_null($fx->avg_rate)) {
                    $refRate = (float)$fx->avg_rate;
                } elseif (!is_null($fx->closing_rate)) {
                    $refRate = (float)$fx->closing_rate;
                } elseif (!is_null($closingRate)) {
                    $refRate = (float)$closingRate;
                } else {
                    $refRate = (float)$fx->exchange_rate;
                }

                $openState[$key] = [
                    'ref_rate' => $refRate,
                    'open_base' => $rem,
                ];
            } else {
                $refRate = $openState[$key]['ref_rate'];
                $openState[$key]['open_base'] += $rem;
            }

            $exRate = (float)$fx->exchange_rate;

            if ($fx->ledger_type === 'customer') {
                $diffRate = $exRate - $refRate;
            } else {
                if ($fx->voucher_type === 'payment') {
                    $diffRate = $refRate - $exRate;
                } else {
                    $diffRate = $exRate - $refRate;
                }
            }

            $openingUnrealised += round($diffRate * $rem, 2);
        }

        // ------------------------------------------------
        // 4) BUILD ROWS (Tally style)
        // ------------------------------------------------
        $rows   = [];
        $sn     = $request->start + 1;

        $totalRealisedGain   = 0;
        $totalRealisedLoss   = 0;
        $totalUnrealisedGain = 0;
        $totalUnrealisedLoss = 0;

        // ---- Opening row (if any activity before start) ----
        if ($openingRows->count() > 0) {

            $baseCurr  = optional($openingRows->first()->baseCurrency)->code  ?? '';
            $localCurr = optional($openingRows->first()->localCurrency)->code ?? '';

            $baseDebit  = $openingBaseNet > 0 ? $openingBaseNet : 0;
            $baseCredit = $openingBaseNet < 0 ? abs($openingBaseNet) : 0;

            $localDebit  = $openingLocalNet > 0 ? $openingLocalNet : 0;
            $localCredit = $openingLocalNet < 0 ? abs($openingLocalNet) : 0;

            $rows[] = [
                'sn'         => $sn++,
                'date'       => date('d-m-Y', strtotime($start . ' -1 day')),
                'party'      => 'Opening Balance',
                'voucher'    => 'OPEN',
                'voucher_no' => '-',
                'exchange'   => $closingRate,

                'base_debit'  => $baseDebit  ? $baseDebit  . " ($baseCurr)"  : 0,
                'base_credit' => $baseCredit ? $baseCredit . " ($baseCurr)"  : 0,
                'local_debit' => $localDebit ? $localDebit . " ($localCurr)" : 0,
                'local_credit' => $localCredit ? $localCredit . " ($localCurr)" : 0,

                'avg_rate'   => $closingRate,
                'diff'       => null,
                'realised'   => 0,
                'unrealised' => $openingUnrealised,
            ];

            if ($openingUnrealised > 0) $totalUnrealisedGain += $openingUnrealised;
            if ($openingUnrealised < 0) $totalUnrealisedLoss += abs($openingUnrealised);
        }

        // ---- In-range rows ----
        foreach ($rowsDB as $fx) {

            $currBase  = $fx->baseCurrency->code  ?? '';
            $currLocal = $fx->localCurrency->code ?? '';

            $realised   = $fx->realised_gain - $fx->realised_loss;
            $unrealised = $fx->unrealised_gain - $fx->unrealised_loss; // DB level (0 for now)

            if ($realised > 0) $totalRealisedGain += $realised;
            if ($realised < 0) $totalRealisedLoss += abs($realised);

            if ($unrealised > 0) $totalUnrealisedGain += $unrealised;
            if ($unrealised < 0) $totalUnrealisedLoss += abs($unrealised);

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
        }

        return response()->json([
            'draw'            => intval($request->draw),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsTotal,
            'data'            => $rows,
            // Totals yaha chaho to add kar sakte ho future me, abhi DataTable me footer logic tumhare hisaab se rahega
        ]);
    }
}
