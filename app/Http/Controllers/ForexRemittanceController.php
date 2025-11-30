<?php

namespace App\Http\Controllers;

use App\Models\ForexRemittance;
use App\Models\ForexMatch;
use App\Models\ForexRate;
use App\Models\Currency;
use App\Models\Party;
use App\Services\ForexMatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ForexRemittanceController extends Controller
{
    /**
     * Store a new forex remittance (Sale / Purchase / Receipt / Payment)
     * - triggers auto-match (FIFO) immediately and persists matches
     * - extensive logging for each step
     */
    public function store(Request $request)
    {
        Log::info('[ForexRemittanceController@store] Entered store()', ['request' => $request->all()]);

        $rules = [
            'party_id' => 'required|integer|exists:parties,id',
            'transaction_date' => 'required|date',
            'base_currency_id' => 'required|integer|exists:currencies,id',
            'local_currency_id' => 'required|integer|exists:currencies,id',
            'base_amount' => 'required|numeric|min:0.0001',
            'voucher_type' => 'required|in:receipt,payment,sale,purchase',
            'voucher_no' => 'required|string',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            Log::warning('[ForexRemittanceController@store] Validation failed', ['errors' => $validator->errors()->all()]);
            return back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();
        try {
            // Fetch currencies
            $baseCurrency = Currency::find($request->base_currency_id);
            $localCurrency = Currency::find($request->local_currency_id);

            Log::info('[ForexRemittanceController@store] Fetched currencies', [
                'base' => $baseCurrency ? $baseCurrency->toArray() : null,
                'local' => $localCurrency ? $localCurrency->toArray() : null,
            ]);

            // Exchange rate priority
            $exchangeRate = $request->input('exchange_rate');
            if (!$exchangeRate || floatval($exchangeRate) == 0) {
                $exchangeRate = $localCurrency->exchange_rate ?? ($baseCurrency->exchange_rate ?? 1);
                Log::info('[ForexRemittanceController@store] Using fallback exchange_rate', ['exchange_rate' => $exchangeRate]);
            } else {
                Log::info('[ForexRemittanceController@store] Using provided exchange_rate', ['exchange_rate' => $exchangeRate]);
            }

            // Local amount
            $baseAmount = floatval($request->input('base_amount'));
            $localAmount = $request->input('local_amount');
            if (!$localAmount || floatval($localAmount) == 0) {
                $localAmount = round($baseAmount * floatval($exchangeRate), 4);
                Log::info('[ForexRemittanceController@store] Auto-calculated local_amount', ['base_amount' => $baseAmount, 'exchange_rate' => $exchangeRate, 'local_amount' => $localAmount]);
            } else {
                $localAmount = floatval($localAmount);
                Log::info('[ForexRemittanceController@store] Provided local_amount used', ['local_amount' => $localAmount]);
            }

            // Avg rate fallback (local/base)
            $avgRate = $request->input('avg_rate');
            if (!$avgRate || floatval($avgRate) == 0) {
                $avgRate = $baseAmount > 0 ? round($localAmount / $baseAmount, 6) : floatval($exchangeRate);
                Log::info('[ForexRemittanceController@store] Auto-calculated avg_rate', ['avg_rate' => $avgRate]);
            } else {
                $avgRate = floatval($avgRate);
                Log::info('[ForexRemittanceController@store] Provided avg_rate used', ['avg_rate' => $avgRate]);
            }

            // Prepare payload
            $data = [
                'party_id' => $request->party_id,
                'party_type' => $request->input('party_type'),
                'transaction_date' => Carbon::parse($request->transaction_date)->toDateString(),
                'base_currency_id' => $baseCurrency->id,
                'base_amount' => $baseAmount,
                'local_currency_id' => $localCurrency->id,
                'exchange_rate' => floatval($exchangeRate),
                'local_amount' => $localAmount,
                'voucher_type' => $request->voucher_type,
                'voucher_no' => $request->voucher_no,
                'avg_rate' => $avgRate,
                'closing_rate' => $request->input('closing_rate') ? floatval($request->input('closing_rate')) : null,
                'remarks' => $request->input('remarks'),
                // initialise settlement tracking
                'settled_base_amount' => 0,
                'remaining_base_amount' => $baseAmount,
            ];

            Log::info('[ForexRemittanceController@store] Prepared remittance payload', ['payload' => $data]);

            // Persist remittance
            $remittance = ForexRemittance::create($data);
            Log::info('[ForexRemittanceController@store] Created ForexRemittance', ['remittance_id' => $remittance->id, 'remittance' => $remittance->toArray()]);

            // Immediately attempt auto-matching (FIFO) via the service
            try {
                Log::info('[ForexRemittanceController@store] Calling ForexMatchingService->autoMatchForRemittance', ['remittance_id' => $remittance->id]);
                $matcher = new ForexMatchingService();
                $matcher->autoMatchForRemittance($remittance);
                Log::info('[ForexRemittanceController@store] ForexMatchingService completed', ['remittance_id' => $remittance->id]);
            } catch (\Throwable $e) {
                // do not fail store if matching error; log for debugging
                Log::error('[ForexRemittanceController@store] Exception during matching', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }

            DB::commit();

            Log::info('[ForexRemittanceController@store] Commit successful. Store finished', ['remittance_id' => $remittance->id]);
            return redirect()->back()->with('success', 'Forex remittance saved successfully.');
        } catch (\Throwable $e) {

            DB::rollBack();
            Log::error('[ForexRemittanceController@store] Exception caught, rolled back', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            print_r("Error saving remittance: " . $e->getMessage());
            //return redirect()->back()->with('error', 'Failed to save remittance. Check logs.');
        }
    }

    /**
     * Data endpoint for server-side DataTable ledger: forexRemittanceData()
     * - computes realised (only invoices) by summing persisted ForexMatch.realised_gain_loss (invoice side)
     * - unrealised computed per-row for remaining_base_amount using closing rate fallback -> avg_rate
     */

    public function forexRemittanceData(Request $request)
    {
        Log::info('[ForexRemittanceController@forexRemittanceData] Entered', ['request' => $request->all()]);

        $partyType = $request->input('party_type');
        $currencyId = $request->input('currency_id') ? intval($request->input('currency_id')) : null;
        $startingDate = $request->input('starting_date') ? Carbon::parse($request->input('starting_date'))->toDateString() : null;
        $endingDate = $request->input('ending_date') ? Carbon::parse($request->input('ending_date'))->toDateString() : null;

        Log::info('[ForexRemittanceController@forexRemittanceData] Filters', compact('partyType', 'currencyId', 'startingDate', 'endingDate'));

        $query = ForexRemittance::with(['matchesAsInvoice', 'matchesAsSettlement', 'baseCurrency', 'localCurrency', 'party']);

        if ($partyType) {
            $query->where('party_type', $partyType);
        }

        if ($currencyId && $currencyId !== 0) {
            $query->where(function ($q) use ($currencyId) {
                $q->where('base_currency_id', $currencyId)
                    ->orWhere('local_currency_id', $currencyId);
            });
        }

        if ($startingDate && $endingDate) {
            $query->whereBetween('transaction_date', [$startingDate, $endingDate]);
        }

        $start = intval($request->input('start', 0));
        $length = intval($request->input('length', 10));
        $draw = intval($request->input('draw', 1));

        $totalRecords = $query->count();
        $rows = $query->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->offset($start)
            ->limit($length)
            ->get();

        $data = [];
        $totals = [
            'base_debit' => 0,
            'base_credit' => 0,
            'local_debit' => 0,
            'local_credit' => 0,
            'realised_gain' => 0,
            'realised_loss' => 0,
            'unrealised_gain' => 0,
            'unrealised_loss' => 0
        ];

        foreach ($rows as $i => $r) {

            // ----------------------------
            // 1. DR/CR MAPPING
            // ----------------------------
            $baseDebit = $baseCredit = $localDebit = $localCredit = 0;

            switch ($r->voucher_type) {
                case 'sale':
                    $baseDebit  = $r->base_amount;
                    $localDebit  = $r->local_amount;
                    break;
                case 'purchase':
                    $baseCredit = $r->base_amount;
                    $localCredit = $r->local_amount;
                    break;
                case 'receipt':
                    $baseCredit = $r->base_amount;
                    $localCredit = $r->local_amount;
                    break;
                case 'payment':
                    $baseDebit  = $r->base_amount;
                    $localDebit  = $r->local_amount;
                    break;
            }

            $totals['base_debit']  += $baseDebit;
            $totals['base_credit'] += $baseCredit;
            $totals['local_debit'] += $localDebit;
            $totals['local_credit'] += $localCredit;


            // ----------------------------
            // 2. DETERMINE ROW RATE
            // ----------------------------
            $rowRate = floatval($r->exchange_rate ?? $r->avg_rate ?? 0);


            // ----------------------------
            // 3. DETERMINE CLOSING RATE
            // ----------------------------
            $closingRate = null;

            // A) If closing_rate is stored directly in remittance row → PRIORITY #1
            if ($r->closing_rate !== null) {
                $closingRate = floatval($r->closing_rate);
            }

            // B) Else resolve via ForexRate table
            if ($closingRate === null && $endingDate) {
                $closingRate = $this->getClosingRateForCurrencyOnDate($r->base_currency_id, $endingDate);
            }

            if ($closingRate === null) {
                $closingRate = $this->getLatestClosingRateForCurrency($r->base_currency_id);
            }

            // C) Fallback
            if ($closingRate === null) {
                $closingRate = floatval($r->avg_rate ?? $rowRate);
            }


            // ----------------------------
            // 4. REALISED GAIN/LOSS (INVOICE ONLY)
            // ----------------------------
            $realised = 0;
            if (in_array($r->voucher_type, ['sale', 'purchase'])) {
                $sum = ForexMatch::where('invoice_id', $r->id)->sum('realised_gain_loss');
                $realised = floatval($sum);
            }


            // ----------------------------
            // 5. UNREALISED GAIN/LOSS
            // ----------------------------
            $remainingBase = floatval($r->remaining_base_amount);
            $unrealised = 0;

            if ($remainingBase > 0 && $closingRate !== null) {
                $unrealised = $remainingBase * ($closingRate - $rowRate);
            }


            // ----------------------------
            // 6. DIFF LOGIC (final memory rules)
            // ----------------------------
            $diff = "";

            $isInvoice    = in_array($r->voucher_type, ['sale', 'purchase']);
            $isSettlement = in_array($r->voucher_type, ['receipt', 'payment']);

            $invoiceMatches    = $r->matchesAsInvoice;
            $settlementMatches = $r->matchesAsSettlement;

            // INVOICE FULLY MATCHED → weighted settlement rate
            if ($isInvoice && $remainingBase == 0 && $invoiceMatches->count() > 0) {

                $totalBase = 0;
                $weightedSettlementRate = 0;

                foreach ($invoiceMatches as $m) {
                    $totalBase += $m->matched_base_amount;
                    $weightedSettlementRate += ($m->matched_base_amount * $m->settlement_rate);
                }

                if ($totalBase > 0) {
                    $effectiveRate = $weightedSettlementRate / $totalBase;
                    $diff = round($effectiveRate - $rowRate, 6);
                }
            }

            // INVOICE UNMATCHED → diff = closing - rowRate
            elseif ($isInvoice && $remainingBase > 0) {
                $diff = round($closingRate - $rowRate, 6);
            }

            // SETTLEMENT MATCHED → diff blank
            elseif ($isSettlement && $settlementMatches->count() > 0) {
                $diff = "";
            }

            // SETTLEMENT UNMATCHED → diff = rowRate - closingRate
            elseif ($isSettlement && $remainingBase > 0) {
                $diff = round($rowRate - $closingRate, 6);
            }


            // ----------------------------
            // 7. TOTALS
            // ----------------------------
            if ($realised >= 0) $totals['realised_gain'] += $realised;
            else                $totals['realised_loss'] += abs($realised);

            if ($unrealised >= 0) $totals['unrealised_gain'] += $unrealised;
            else                  $totals['unrealised_loss'] += abs($unrealised);


            // ----------------------------
            // 8. BUILD OUTPUT ROW
            // ----------------------------
            $data[] = [
                'sn'            => $start + $i + 1,
                'date'          => $r->transaction_date,
                'particulars'   => ($r->party ? $r->party->name : 'Unknown') . ' | ' . ucfirst($r->voucher_type),
                'vch_type'      => ucfirst($r->voucher_type),
                'vch_no'        => $r->voucher_no,
                'exch_rate'     => number_format($rowRate, 4),
                'base_debit'    => $baseDebit ? number_format($baseDebit, 4, '.', ',') : '',
                'base_credit'   => $baseCredit ? number_format($baseCredit, 4, '.', ',') : '',
                'local_debit'   => $localDebit ? number_format($localDebit, 4, '.', ',') : '',
                'local_credit'  => $localCredit ? number_format($localCredit, 4, '.', ',') : '',
                'avg_rate'      => number_format(floatval($r->avg_rate), 6),
                'closing_rate'  => number_format(floatval($closingRate), 6),
                'diff'          => $diff === "" ? "" : number_format($diff, 6),
                'realised'      => round($realised, 4),
                'unrealised'    => round($unrealised, 4),
                'remarks'       => $r->remarks ?? ''
            ];
        }

        $finalGainLoss =
            ($totals['realised_gain'] - $totals['realised_loss']) +
            ($totals['unrealised_gain'] - $totals['unrealised_loss']);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data' => $data,
            'totals' => [
                'realised_gain' => round($totals['realised_gain'], 4),
                'realised_loss' => round($totals['realised_loss'], 4),
                'unrealised_gain' => round($totals['unrealised_gain'], 4),
                'unrealised_loss' => round($totals['unrealised_loss'], 4),
                'final_gain_loss' => round($finalGainLoss, 4)
            ]
        ]);
    }



    /**
     * Helper: get manual closing rate for a currency on a given date
     * Uses rate_date field on forex_rates table. Returns latest closing_rate on or before date or null.
     */
    protected function getClosingRateForCurrencyOnDate(int $currencyId, string $date)
    {
        Log::info('[ForexRemittanceController@getClosingRateForCurrencyOnDate] Looking up closing rate', [
            'currency_id' => $currencyId,
            'date' => $date
        ]);

        $rate = ForexRate::where('currency_id', $currencyId)
            ->where('rate_date', '<=', $date)
            ->orderBy('rate_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($rate) {
            Log::info('[ForexRemittanceController@getClosingRateForCurrencyOnDate] Found closing rate', [
                'closing_rate' => $rate->closing_rate,
                'id' => $rate->id,
                'rate_date' => $rate->rate_date
            ]);
            return floatval($rate->closing_rate);
        }

        Log::info('[ForexRemittanceController@getClosingRateForCurrencyOnDate] No closing rate found');
        return null;
    }

    /**
     * Helper: get latest closing rate for a currency (if date not specified)
     */
    protected function getLatestClosingRateForCurrency(int $currencyId)
    {
        Log::info('[ForexRemittanceController@getLatestClosingRateForCurrency] Looking up latest closing rate', [
            'currency_id' => $currencyId
        ]);

        $rate = ForexRate::where('currency_id', $currencyId)
            ->orderBy('rate_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($rate) {
            Log::info('[ForexRemittanceController@getLatestClosingRateForCurrency] Found latest rate', [
                'closing_rate' => $rate->closing_rate,
                'rate_date' => $rate->rate_date
            ]);
            return floatval($rate->closing_rate);
        }

        Log::info('[ForexRemittanceController@getLatestClosingRateForCurrency] No latest closing rate found');
        return null;
    }
}
