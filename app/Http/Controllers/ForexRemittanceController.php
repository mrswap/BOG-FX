<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Transaction;
use App\Models\Party;
use App\Models\Currency;
use App\Services\TransactionService;
use App\Services\LedgerBuilder;
use Illuminate\Validation\Rule;
use App\Models\ForexMatch;
use App\Models\ForexRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ForexRemittanceController extends Controller
{
    protected TransactionService $txService;
    protected LedgerBuilder $ledgerBuilder;

    public function __construct(TransactionService $txService, LedgerBuilder $ledgerBuilder)
    {
        $this->txService = $txService;
        $this->ledgerBuilder = $ledgerBuilder;
    }

    /**
     * Store transaction and trigger matching
     */
    public function store(Request $request)
    {
        \Log::info('Forex transaction store data: ', [
            'request_data' => $request->all()
        ]);
        $data = $this->validateRequest($request);

        if ($request->hasFile('attachment')) {

            $file = $request->file('attachment');

            $name = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();

            $file->move(public_path('attachment'), $name);

            $data['attachment'] = 'attachment/' . $name;
        }

        // compute local_amount if not provided
        if (empty($data['local_amount']) && isset($data['base_amount'], $data['exchange_rate'])) {
            $data['local_amount'] = round($data['base_amount'] * $data['exchange_rate'], 4);
        }

        // Use DB transaction for safety
        DB::beginTransaction();
        try {
            $tx = $this->txService->create($data);
            DB::commit();
            return back()->with('success', "Transaction saved ({$tx->voucher_no})");
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Forex transaction store error: ' . $e->getMessage(), [
                'payload' => $data,
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withInput()->withErrors(['error' => 'Unable to save transaction. See logs.']);
        }
    }

    /**
     * Show edit form
     */
    public function edit(Transaction $transaction)
    {
        $parties = Party::orderBy('name')->get();
        $currencies = Currency::orderBy('code')->get();

        return view('forex.transactions.edit', compact('transaction', 'parties', 'currencies'));
    }

    /**
     * Update transaction (clears matches + rebuilds bucket)
     */
    /**
     * Update transaction (clears matches + rebuilds bucket)
     */
    public function update(Request $request, Transaction $transaction)
    {

        \Log::info('Forex transaction update data: ', [
            'request_data' => $request->all()
        ]);

        $data = $this->validateRequest($request, $transaction->id);

        if ($request->hasFile('attachment')) {

            if ($transaction->attachment && file_exists(public_path($transaction->attachment))) {
                unlink(public_path($transaction->attachment));
            }

            $file = $request->file('attachment');
            $name = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('attachment'), $name);

            $data['attachment'] = 'attachment/' . $name;
        }


        if (empty($data['local_amount']) && isset($data['base_amount'], $data['exchange_rate'])) {
            $data['local_amount'] = round($data['base_amount'] * $data['exchange_rate'], 4);
        }

        try {
            $updated = $this->txService->update($transaction, $data);

            return redirect()
                ->route('sales.index')
                ->with('success', "Transaction updated ({$updated->voucher_no})");
        } catch (\Throwable $e) {

            \Log::error('Forex transaction update error: ' . $e->getMessage(), [
                'tx_id' => $transaction->id,
                'payload' => $data,
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withInput()->withErrors(['error' => 'Unable to update transaction. See logs.']);
        }
    }

    /**
     * Delete a transaction (clear matches + rebuild)
     */
    public function destroy(Transaction $transaction)
    {
        DB::beginTransaction();
        try {
            $this->txService->delete($transaction);
            DB::commit();

            return redirect()
                ->route('sales.index')
                ->with('success', "Transaction deleted");
        } catch (\Throwable $e) {
            DB::rollBack();

            \Log::error('Forex transaction delete error: ' . $e->getMessage(), [
                'tx_id' => $transaction->id,
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withErrors(['error' => 'Unable to delete transaction. See logs.']);
        }
    }


    /**
     * Party ledger view (uses LedgerBuilder)
     *
     * route example: forex/ledger/{party_id}?from=2025-12-01&to=2025-12-31
     */
    public function ledger(Request $request, int $partyId)
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $party = Party::findOrFail($partyId);
        $rows = $this->ledgerBuilder->build($partyId, $from, $to);

        // compute totals
        $totals = [
            'base_dr' => array_sum(array_column($rows, 'base_dr')),
            'base_cr' => array_sum(array_column($rows, 'base_cr')),
            'local_dr' => array_sum(array_column($rows, 'local_dr')),
            'local_cr' => array_sum(array_column($rows, 'local_cr')),
            'realised' => array_sum(array_column($rows, 'realised')),
            'unrealised' => array_sum(array_column($rows, 'unrealised')),
        ];

        return view('forex.ledger.show', compact('party', 'rows', 'totals', 'from', 'to'));
    }

    /**
     * Simple API endpoint to fetch computed local_amount based on base & rate.
     * Useful for form JS auto-fill.
     */
    public function convertLocalAmount(Request $request)
    {
        $data = $request->validate([
            'base_amount' => 'required|numeric|min:0',
            'exchange_rate' => 'required|numeric|min:0.00001',
        ]);

        $local = round($data['base_amount'] * $data['exchange_rate'], 4);

        return response()->json(['local_amount' => $local]);
    }

    /**
     * Validates request for create/update.
     */
    protected function validateRequest(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'party_type' => ['nullable', Rule::in(['customer', 'supplier'])],
            'party_id' => ['required', 'integer', 'exists:parties,id'],
            'transaction_date' => ['required', 'date'],
            'base_currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'base_amount' => ['required', 'numeric', 'min:0.0001'],
            'closing_rate' => ['nullable', 'numeric'],
            'local_currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['required', 'numeric', 'min:0.0001'],
            'local_amount' => ['nullable', 'numeric'],
            'voucher_type' => ['required', Rule::in(['sale', 'purchase', 'receipt', 'payment'])],
            'voucher_no' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],

        ];

        // ensure voucher_no uniqueness except for current record (on update)
        if ($ignoreId) {
            $rules['voucher_no'][] = Rule::unique('transactions', 'voucher_no')->ignore($ignoreId);
        } else {
            $rules['voucher_no'][] = Rule::unique('transactions', 'voucher_no');
        }

        return $request->validate($rules);
    }

    /**
     * Data endpoint for DataTable — uses LedgerBuilder service to compute rows & totals.
     */
    public function forexRemittanceData(Request $request)
    {
        Log::info("[forexRemittanceData] service-based V1 enter", ['request' => $request->all()]);

        $startingDate = $request->starting_date
            ? Carbon::createFromFormat('d-m-Y', trim($request->starting_date))
            ->toDateString()
            : null;

        $endingDate = $request->ending_date
            ? Carbon::createFromFormat('d-m-Y', trim($request->ending_date))
            ->toDateString()
            : null;

        // build filter options
        $opts = [
            'party_type' => $request->input('party_type') ?: null,
            'currency_id' => $request->input('currency_id') ? intval($request->input('currency_id')) : null,
            'starting_date' => $startingDate, // ✅ Y-m-d
            'ending_date'   => $endingDate,   // ✅ Y-m-d
        ];


        $orderColumnIndex = request('order.0.column');
        $orderDirection   = request('order.0.dir', 'asc');
        $opts['order_column'] = $orderColumnIndex;
        $opts['order_dir']    = $orderDirection;

        // Use LedgerBuilder to get rows (already formatted)
        $result = $this->ledgerBuilder->buildForDataTable($opts);
        $rows   = $result['rows'];
        $global = $result['global_summary'];

        // compute totals using the same logic as earlier (but from service rows)
        $totals = [
            'base_debit' => 0.0,
            'base_credit' => 0.0,
            'local_debit' => 0.0,
            'local_credit' => 0.0,
            'realised_gain' => 0.0,
            'realised_loss' => 0.0,
            'unrealised_gain' => 0.0,
            'unrealised_loss' => 0.0,
            'remaining_local_total' => 0.0,

        ];

        foreach ($rows as $r) {
            // base columns are formatted strings, so parse them
            $bd = is_numeric(str_replace(',', '', $r['base_debit'])) ? floatval(str_replace(',', '', $r['base_debit'])) : 0.0;
            $bc = is_numeric(str_replace(',', '', $r['base_credit'])) ? floatval(str_replace(',', '', $r['base_credit'])) : 0.0;
            $ld = is_numeric(str_replace(',', '', $r['local_debit'])) ? floatval(str_replace(',', '', $r['local_debit'])) : 0.0;
            $lc = is_numeric(str_replace(',', '', $r['local_credit'])) ? floatval(str_replace(',', '', $r['local_credit'])) : 0.0;

            $totals['base_debit'] += $bd;
            $totals['base_credit'] += $bc;
            $totals['local_debit'] += $ld;
            $totals['local_credit'] += $lc;

            // realised & unrealised are numeric already
            $real = floatval($r['realised']);
            $unreal = floatval($r['unrealised']);

            if ($real >= 0) $totals['realised_gain'] += $real;
            else $totals['realised_loss'] += abs($real);
            if ($unreal >= 0) $totals['unrealised_gain'] += $unreal;
            else $totals['unrealised_loss'] += abs($unreal);
            // ⭐ ADD THIS EXACT BLOCK — REQUIRED FOR TOTAL REMAINING LOCAL
            $rlv_raw = $r['remaining_local_value'] ?? 0;

            // convert formatted string or numeric into float
            $rlv = is_numeric($rlv_raw)
                ? floatval($rlv_raw)
                : floatval(str_replace(',', '', $rlv_raw));

            $totals['remaining_local_total'] += $rlv;
        }

        $finalGainLoss = ($totals['realised_gain'] - $totals['realised_loss']) + ($totals['unrealised_gain'] - $totals['unrealised_loss']);

        $totals_payload = [
            'realised_gain' => round($totals['realised_gain'], 4),
            'realised_loss' => round($totals['realised_loss'], 4),
            'unrealised_gain' => round($totals['unrealised_gain'], 4),
            'unrealised_loss' => round($totals['unrealised_loss'], 4),
            'final_gain_loss' => round($finalGainLoss, 4),
            'remaining_local_total' => round($totals['remaining_local_total'], 4),

        ];

        $response = [
            'draw' => intval($request->input('draw', 1)),
            'recordsTotal' => count($rows),
            'recordsFiltered' => count($rows),
            'data' => $rows,
            'totals' => $totals_payload,
            'global' => $global
        ];

        Log::info('[forexRemittanceData] returning rows=' . count($rows));
        return response()->json($response);
    }

    public function getPartyWiseReport(Request $request)
    {
        $startingDate = $request->starting_date
            ? Carbon::createFromFormat('d-m-Y', trim($request->starting_date))
            ->toDateString()
            : null;

        $endingDate = $request->ending_date
            ? Carbon::createFromFormat('d-m-Y', trim($request->ending_date))
            ->toDateString()
            : null;
        // -----------------------------
        // 1) Build filter options
        // -----------------------------
        $opts = [
            'party_id'      => $request->input('party_id'),
            'starting_date' => $startingDate, // ✅ Y-m-d
            'ending_date'   => $endingDate,   // ✅ Y-m-d
            'txn_group'     => $request->input('txn_group'),
        ];

        $orderColumnIndex = request('order.0.column');
        $orderDirection   = request('order.0.dir', 'asc');
        $opts['order_column'] = $orderColumnIndex;
        $opts['order_dir']    = $orderDirection;


        // -----------------------------
        // 2) Get allowed transaction IDs
        // -----------------------------
        $allowedIds = app(\App\Services\PartyWiseFilterService::class)
            ->filter($opts);

        // -----------------------------
        // 3) Fetch ledger rows (same format)
        // -----------------------------
        $opts['allowed_tx_ids'] = $allowedIds;

        $built = $this->ledgerBuilder->buildForDataTable($opts);

        $rows = $built['rows'];                 // ⭐ SAFE rows
        $global = $built['global_summary'];     // ⭐ USE THIS
        // -----------------------------
        // 4) Compute totals
        // -----------------------------
        $totals = [
            'base_debit' => 0.0,
            'base_credit' => 0.0,
            'local_debit' => 0.0,
            'local_credit' => 0.0,
            'realised_gain' => 0.0,
            'realised_loss' => 0.0,
            'unrealised_gain' => 0.0,
            'unrealised_loss' => 0.0,
        ];

        foreach ($rows as $r) {

            // Remove formatting
            $bd = is_numeric(str_replace(',', '', $r['base_debit'])) ? floatval(str_replace(',', '', $r['base_debit'])) : 0.0;
            $bc = is_numeric(str_replace(',', '', $r['base_credit'])) ? floatval(str_replace(',', '', $r['base_credit'])) : 0.0;
            $ld = is_numeric(str_replace(',', '', $r['local_debit'])) ? floatval(str_replace(',', '', $r['local_debit'])) : 0.0;
            $lc = is_numeric(str_replace(',', '', $r['local_credit'])) ? floatval(str_replace(',', '', $r['local_credit'])) : 0.0;

            // Accumulate base & local totals
            $totals['base_debit'] += $bd;
            $totals['base_credit'] += $bc;
            $totals['local_debit'] += $ld;
            $totals['local_credit'] += $lc;

            // Realised & unrealised
            $real = floatval($r['realised']);
            $unreal = floatval($r['unrealised']);

            if ($real >= 0) $totals['realised_gain'] += $real;
            else $totals['realised_loss'] += abs($real);

            if ($unreal >= 0) $totals['unrealised_gain'] += $unreal;
            else $totals['unrealised_loss'] += abs($unreal);
        }

        // FINAL GAIN LOSS
        $finalGainLoss =
            ($totals['realised_gain'] - $totals['realised_loss']) +
            ($totals['unrealised_gain'] - $totals['unrealised_loss']);

        $totals_payload = [
            'realised_gain'     => round($totals['realised_gain'], 4),
            'realised_loss'     => round($totals['realised_loss'], 4),
            'unrealised_gain'   => round($totals['unrealised_gain'], 4),
            'unrealised_loss'   => round($totals['unrealised_loss'], 4),
            'final_gain_loss'   => round($finalGainLoss, 4),
        ];

        // -----------------------------
        // 5) RETURN EXACT SAME FORMAT
        // -----------------------------

        return response()->json([
            'draw'            => intval($request->draw),
            'recordsTotal'    => count($rows),
            'recordsFiltered' => count($rows),
            'data'            => $rows,
            'totals'          => $totals_payload,
            'global'          => $global   // ⭐ HERE
        ]);
    }

    public function getInvoiceWiseReport(Request $request)
    {
        try {


            $start = $request->starting_date
                ? Carbon::createFromFormat('d-m-Y', trim($request->starting_date))
                ->toDateString()
                : null;

            $end = $request->ending_date
                ? Carbon::createFromFormat('d-m-Y', trim($request->ending_date))
                ->toDateString()
                : null;

            $invoiceId = $request->invoice_id;


            // -------------------------------------
            // CASE 1: ALL invoices → use normal LB
            // -------------------------------------
            if ($invoiceId === "all") {

                $opts = [
                    'starting_date' => $start,
                    'ending_date'   => $end,
                    // no filter → all transactions
                ];
                $orderColumnIndex = request('order.0.column');
                $orderDirection   = request('order.0.dir', 'asc');
                $opts['order_column'] = $orderColumnIndex;
                $opts['order_dir']    = $orderDirection;

                $built = $this->ledgerBuilder->buildForDataTable($opts);

                $rows   = $built['rows'];              // ✅ FIX
                $global = $built['global_summary'];    // ✅ FIX

            } else {

                // -------------------------------------
                // CASE 2: Specific Invoice Selected
                // -------------------------------------
                $invoice = Transaction::find($invoiceId);

                if (!$invoice) {
                    return response()->json([
                        'draw' => intval($request->draw),
                        'recordsTotal' => 0,
                        'recordsFiltered' => 0,
                        'data' => [],
                        'totals' => []
                    ]);
                }

                // Get all related settlements
                $settlementIds = ForexMatch::where('invoice_id', $invoiceId)
                    ->pluck('settlement_id')
                    ->toArray();

                // Build ID set: invoice + all its matches
                $allowedIds = array_unique(
                    array_merge([$invoiceId], $settlementIds)
                );

                // -------------------------------------
                // Call LedgerBuilder with transaction_id filter
                // -------------------------------------
                $opts = [
                    'starting_date' => $start,
                    'ending_date'   => $end,
                    'allowed_tx_ids' => $allowedIds,   // ⭐ important
                ];
                $orderColumnIndex = request('order.0.column');
                $orderDirection   = request('order.0.dir', 'asc');
                $opts['order_column'] = $orderColumnIndex;
                $opts['order_dir']    = $orderDirection;

                $built = $this->ledgerBuilder->buildForDataTable($opts);

                $rows = $built['rows'];                 // ⭐ SAFE rows
                $global = $built['global_summary'];     // ⭐ USE THIS
            }


            // -------------------------------------
            // FOOTER TOTALS (same as forexRemittanceData)
            // -------------------------------------
            $totals = [
                'base_debit' => 0.0,
                'base_credit' => 0.0,
                'local_debit' => 0.0,
                'local_credit' => 0.0,
                'realised_gain' => 0.0,
                'realised_loss' => 0.0,
                'unrealised_gain' => 0.0,
                'unrealised_loss' => 0.0,
            ];

            foreach ($rows as $r) {

                $bd = floatval(str_replace(',', '', $r['base_debit'] ?? 0));
                $bc = floatval(str_replace(',', '', $r['base_credit'] ?? 0));
                $ld = floatval(str_replace(',', '', $r['local_debit'] ?? 0));
                $lc = floatval(str_replace(',', '', $r['local_credit'] ?? 0));

                $totals['base_debit']  += $bd;
                $totals['base_credit'] += $bc;
                $totals['local_debit'] += $ld;
                $totals['local_credit'] += $lc;

                $real = floatval($r['realised']);
                $unreal = floatval($r['unrealised']);

                if ($real >= 0) $totals['realised_gain'] += $real;
                else $totals['realised_loss'] += abs($real);
                if ($unreal >= 0) $totals['unrealised_gain'] += $unreal;
                else $totals['unrealised_loss'] += abs($unreal);
            }

            return response()->json([
                'draw' => intval($request->draw),
                'recordsTotal' => count($rows),
                'recordsFiltered' => count($rows),
                'data' => $rows,
                'totals' => [
                    'realised_gain' => $totals['realised_gain'],
                    'realised_loss' => $totals['realised_loss'],
                    'unrealised_gain' => $totals['unrealised_gain'],
                    'unrealised_loss' => $totals['unrealised_loss'],
                ],
                'global' => $global
            ]);
        } catch (\Throwable $e) {

            \Log::error("Invoice-wise report error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Server error'
            ], 500);
        }
    }

    public function getCurrencyWiseReport(Request $request)
    {
        try {

            // ================================
            // DATE PARSING
            // ================================
            $start = $request->starting_date
                ? Carbon::createFromFormat('d-m-Y', trim($request->starting_date))
                ->toDateString()
                : null;

            $end = $request->ending_date
                ? Carbon::createFromFormat('d-m-Y', trim($request->ending_date))
                ->toDateString()
                : null;

            $partyId         = $request->party_id;
            $baseCurrencyId  = $request->base_currency_id;
            $localCurrencyId = $request->local_currency_id;

            // ================================
            // BUILD OPTIONS FOR LEDGER BUILDER
            // ================================
            $opts = [
                'starting_date'     => $start,
                'ending_date'       => $end,
                'party_id'          => $partyId,
                'base_currency_id'  => $baseCurrencyId,
                'local_currency_id' => $localCurrencyId,
            ];

            // DataTable Sorting
            $opts['order_column'] = $request->input('order.0.column');
            $opts['order_dir']    = $request->input('order.0.dir', 'asc');

            // ================================
            // FETCH SORTED + FILTERED DATA
            // ================================
            $built  = $this->ledgerBuilder->buildForDataTable($opts);
            $rows   = $built['rows'];
            $global = $built['global_summary'];

            // ================================
            // FOOTER TOTALS
            // ================================
            $totals = [
                'realised_gain'   => 0.0,
                'realised_loss'   => 0.0,
                'unrealised_gain' => 0.0,
                'unrealised_loss' => 0.0,
            ];

            foreach ($rows as $r) {

                $real   = floatval($r['realised']);
                $unreal = floatval($r['unrealised']);

                if ($real >= 0) {
                    $totals['realised_gain'] += $real;
                } else {
                    $totals['realised_loss'] += abs($real);
                }

                if ($unreal >= 0) {
                    $totals['unrealised_gain'] += $unreal;
                } else {
                    $totals['unrealised_loss'] += abs($unreal);
                }
            }

            return response()->json([
                'draw'            => intval($request->draw),
                'recordsTotal'    => count($rows),
                'recordsFiltered' => count($rows),
                'data'            => $rows,
                'totals' => [
                    'realised_gain'   => round($totals['realised_gain'], 4),
                    'realised_loss'   => round($totals['realised_loss'], 4),
                    'unrealised_gain' => round($totals['unrealised_gain'], 4),
                    'unrealised_loss' => round($totals['unrealised_loss'], 4),
                ],
                'global' => $global
            ]);
        } catch (\Throwable $e) {

            \Log::error("Currency wise report error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Server Error'
            ], 500);
        }
    }


    public function exchChangeRatesReportData(Request $request)
    {
        try {

            // ===============================
            // 1️⃣ DATE GUARD
            // ===============================

            $start = $request->starting_date
                ? Carbon::createFromFormat('d-m-Y', $request->starting_date)->startOfDay()
                : null;

            $end = $request->ending_date
                ? Carbon::createFromFormat('d-m-Y', $request->ending_date)->endOfDay()
                : null;

            $q = Transaction::whereNotNull('exchange_rate');

            if ($start && $end) {
                $q->whereBetween('transaction_date', [$start, $end]);
            }


            if (!empty($request->base_currency_id)) {
                $q->where('base_currency_id', $request->base_currency_id);
            }

            if (!empty($request->local_currency_id)) {
                $q->where('local_currency_id', $request->local_currency_id);
            }

            $txs = $q->orderBy('transaction_date')
                ->orderBy('id')
                ->get();

            // ===============================
            // 3️⃣ GROUP BY DATE
            // ===============================
            $grouped = [];

            foreach ($txs as $tx) {

                $date = Carbon::parse($tx->transaction_date)->format('Y-m-d');

                $grouped[$date][] = [
                    'voucher_type' => ucfirst($tx->voucher_type),
                    'voucher_no'   => $tx->voucher_no,
                    'rate'         => (float)$tx->exchange_rate
                ];
            }

            // ===============================
            // 4️⃣ BUILD ROWS
            // ===============================
            $rows = [];
            $sn = 1;

            foreach ($grouped as $date => $items) {

                $rates = array_column($items, 'rate');
                $avg   = count($rates) ? array_sum($rates) / count($rates) : 0;

                $rows[] = [
                    'sn'      => $sn++,
                    'date'    => Carbon::parse($date)->format('d-m-Y'),
                    'entries' => array_map(function ($i) {
                        return [
                            'voucher_type' => $i['voucher_type'],
                            'voucher_no'   => $i['voucher_no'],
                            'rate'         => number_format($i['rate'], 6, '.', '')
                        ];
                    }, $items),
                    'avg_rate' => number_format($avg, 6, '.', '')
                ];
            }

            // ===============================
            // 5️⃣ RESPONSE
            // ===============================
            return response()->json([
                'draw'            => intval($request->draw),
                'recordsTotal'    => count($rows),
                'recordsFiltered' => count($rows),
                'data'            => $rows
            ]);
        } catch (\Throwable $e) {

            \Log::error('[ExchangeRateReport]', [
                'msg' => $e->getMessage()
            ]);

            return response()->json([
                'draw' => intval($request->draw),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => []
            ], 500);
        }
    }
}
