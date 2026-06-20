<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\ForexMatch;
use App\Models\Ledger;
use App\Models\Party;
use App\Models\Transaction;
use App\Services\LedgerBuilder;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ForexRemittanceController extends Controller
{
    protected TransactionService $txService;
    protected LedgerBuilder $ledgerBuilder;

    public function __construct(TransactionService $txService, LedgerBuilder $ledgerBuilder)
    {
        $this->txService     = $txService;
        $this->ledgerBuilder = $ledgerBuilder;
    }

    /**
     * Store transaction and trigger matching
     */
    public function store(Request $request)
    {
        \Log::info('==================================================');
        \Log::info('FOREX STORE START');
        \Log::info('==================================================');

        \Log::info('STEP 1: Incoming Request', [
            'request_data' => $request->all(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */

        $data = $this->validateRequest($request);

        \Log::info('STEP 2: Validated Data', [
            'validated_data' => $data,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Clean Manual Matches
        |--------------------------------------------------------------------------
        */

        if (! empty($data['manual_matches'])) {

            \Log::info('STEP 3A: Raw Manual Matches', [
                'manual_matches_before_clean' =>
                $data['manual_matches'],
            ]);

            $data['manual_matches'] = collect(
                $data['manual_matches']
            )
                ->filter(function ($row) {

                    return;
                    ! empty($row['transaction_id'])
                        &&
                        ! empty($row['amount'])
                        &&
                        (float) $row['amount'] > 0;
                })
                ->values()
                ->toArray();

            \Log::info('STEP 3B: Cleaned Manual Matches', [
                'manual_matches_after_clean' =>
                $data['manual_matches'],
            ]);
        } else {

            \Log::warning('STEP 3C: No Manual Matches Received');
        }

        /*
        |--------------------------------------------------------------------------
        | Attachment Upload
        |--------------------------------------------------------------------------
        */

        if ($request->hasFile('attachment')) {

            \Log::info('STEP 4: Attachment Upload Started');

            $file = $request->file('attachment');

            $name =
                time()
                . '_'
                . uniqid()
                . '.'
                . $file->getClientOriginalExtension();

            $file->move(
                public_path('attachment'),
                $name
            );

            $data['attachment'] =
                'attachment/' . $name;

            \Log::info('STEP 5: Attachment Uploaded', [
                'attachment' => $data['attachment'],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Auto Calculate Local Amount
        |--------------------------------------------------------------------------
        */

        if (
            empty($data['local_amount'])
            &&
            isset(
                $data['base_amount'],
                $data['exchange_rate']
            )
        ) {

            $data['local_amount'] = round(
                (float) $data['base_amount']
                    *
                    (float) $data['exchange_rate'],
                4
            );

            \Log::info('STEP 6: Local Amount Auto Calculated', [
                'local_amount' => $data['local_amount'],
            ]);
        }

        DB::beginTransaction();

        try {

            \Log::info('STEP 7: DB Transaction Started');

            /*
        |--------------------------------------------------------------------------
        | Create Transaction
        |--------------------------------------------------------------------------
        */

            $data['user_id'] = auth()->id();

            $tx = $this->txService->create($data);

            \Log::info('STEP 8: Transaction Created', [
                'transaction' => $tx->toArray(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Verify Matches
            |--------------------------------------------------------------------------
            */

            $matches = ForexMatch::where(
                'invoice_id',
                $tx->id
            )
                ->orWhere(
                    'settlement_id',
                    $tx->id
                )
                ->get();

            \Log::info('STEP 9: Matches After Create', [
                'count'   => $matches->count(),
                'matches' => $matches->toArray(),
            ]);

            DB::commit();

            \Log::info('STEP 10: DB Commit Successful');

            \Log::info('==================================================');
            \Log::info('FOREX STORE SUCCESS');
            \Log::info('==================================================');

            return back()->with(
                'success',
                "Transaction saved ({$tx->voucher_no})"
            );
        } catch (\Throwable $e) {

            DB::rollBack();

            \Log::error(
                'FOREX STORE ERROR: ' . $e->getMessage(),
                [
                    'payload' => $data,
                    'trace'   => $e->getTraceAsString(),
                ]
            );

            \Log::info('==================================================');
            \Log::info('FOREX STORE FAILED');
            \Log::info('==================================================');

            return back()
                ->withInput()
                ->withErrors([
                    'error' =>
                    'Unable to save transaction. See logs.',
                ]);
        }
    }

    /**
     * Show edit form
     */
    public function edit(Transaction $transaction)
    {
        if ($transaction->user_id != auth()->id()) {
            abort(403);
        }
        $parties    = Party::orderBy('name')->get();
        $currencies = Currency::orderBy('code')->get();

        return view('forex.transactions.edit', compact('transaction', 'parties', 'currencies'));
    }

    public function update(Request $request, Transaction $transaction)
    {

        \Log::info("==================================================");
        \Log::info("FOREX UPDATE START");
        \Log::info("==================================================");

        \Log::info(
            'STEP 1: Incoming Request',
            [
                'transaction_id' => $transaction->id,
                'request_data'   => $request->all(),
            ]
        );

        if ($transaction->user_id != auth()->id()) {
            abort(403);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */

        $data = $this->validateRequest(
            $request,
            $transaction->id
        );

        \Log::info(
            'STEP 2: Validated Data',
            [
                'validated_data' => $data,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Clean Manual Matches
        |--------------------------------------------------------------------------
        */

        if (! empty($data['manual_matches'])) {

            \Log::info(
                'STEP 3A: Raw Manual Matches',
                [
                    'manual_matches_before_clean' => $data['manual_matches'],
                ]
            );

            $data['manual_matches'] = collect(
                $data['manual_matches']
            )
                ->filter(function ($row) {

                    return;
                    ! empty($row['transaction_id'])
                        &&
                        ! empty($row['amount'])
                        &&
                        (float) $row['amount'] > 0;
                })
                ->values()
                ->toArray();

            \Log::info(
                'STEP 3B: Cleaned Manual Matches',
                [
                    'manual_matches_after_clean' => $data['manual_matches'],
                ]
            );
        } else {

            \Log::warning(
                'STEP 3C: No Manual Matches Received'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | EXISTING MATCHES BEFORE UPDATE
        |--------------------------------------------------------------------------
        */

        try {

            $existingMatches = ForexMatch::where(function ($q) use ($transaction) {

                $q->where('invoice_id', $transaction->id)
                    ->orWhere('settlement_id', $transaction->id);
            })->get();

            \Log::info(
                'STEP 4: Existing Forex Matches',
                [
                    'count'   => $existingMatches->count(),
                    'matches' => $existingMatches->toArray(),
                ]
            );
        } catch (\Throwable $e) {

            \Log::error(
                'STEP 4 ERROR: Unable To Load Existing Matches',
                [
                    'error' => $e->getMessage(),
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Attachment Upload
        |--------------------------------------------------------------------------
        */

        if ($request->hasFile('attachment')) {

            \Log::info(
                'STEP 5A: Attachment Upload Started'
            );

            /*
            |--------------------------------------------------------------------------
            | Delete old attachment
            |--------------------------------------------------------------------------
            */

            if (
                $transaction->attachment
                &&
                file_exists(
                    public_path($transaction->attachment)
                )
            ) {

                \Log::info(
                    'STEP 5B: Deleting Old Attachment',
                    [
                        'path' => $transaction->attachment,
                    ]
                );

                unlink(
                    public_path($transaction->attachment)
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Upload new attachment
            |--------------------------------------------------------------------------
            */

            $file = $request->file('attachment');

            $name =
                time()
                . '_'
                . uniqid()
                . '.'
                . $file->getClientOriginalExtension();

            $file->move(
                public_path('attachment'),
                $name
            );

            $data['attachment'] =
                'attachment/' . $name;

            \Log::info(
                'STEP 5C: New Attachment Uploaded',
                [
                    'attachment' => $data['attachment'],
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Auto Calculate Local Amount
        |--------------------------------------------------------------------------
        */

        if (
            empty($data['local_amount'])
            &&
            isset(
                $data['base_amount'],
                $data['exchange_rate']
            )
        ) {

            $data['local_amount'] = round(
                (float) $data['base_amount']
                    *
                    (float) $data['exchange_rate'],
                4
            );

            \Log::info(
                'STEP 6: Auto Calculated Local Amount',
                [
                    'local_amount' => $data['local_amount'],
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | BEFORE UPDATE SNAPSHOT
        |--------------------------------------------------------------------------
        */

        \Log::info(
            'STEP 7: Transaction Before Update',
            [
                'transaction' => $transaction->toArray(),
            ]
        );

        DB::beginTransaction();

        try {

            \Log::info(
                'STEP 8: DB Transaction Started'
            );

            /*
            |--------------------------------------------------------------------------
            | Update Transaction
            |--------------------------------------------------------------------------
            */

            $updated = $this->txService->update(
                $transaction,
                $data
            );

            \Log::info(
                'STEP 9: Transaction Updated',
                [
                    'updated_transaction' => $updated->fresh()->toArray(),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | MATCHES AFTER UPDATE
            |--------------------------------------------------------------------------
            */

            $matchesAfter = ForexMatch::where(function ($q) use ($updated) {

                $q->where('invoice_id', $updated->id)
                    ->orWhere('settlement_id', $updated->id);
            })->get();

            \Log::info(
                'STEP 10: Matches After Update',
                [
                    'count'   => $matchesAfter->count(),
                    'matches' => $matchesAfter->toArray(),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | PARTY FULL MATCH SNAPSHOT
            |--------------------------------------------------------------------------
            */

            $partyMatches = ForexMatch::where(
                'party_id',
                $updated->party_id
            )->get();

            \Log::info(
                'STEP 11: Party Full Match Snapshot',
                [
                    'party_id'      => $updated->party_id,
                    'total_matches' => $partyMatches->count(),
                    'matches'       => $partyMatches->toArray(),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | PARTY TRANSACTION ORDER
            |--------------------------------------------------------------------------
            */

            $partyTxs = Transaction::where(
                'party_id',
                $updated->party_id
            )
                ->orderBy('transaction_date')
                ->orderByRaw("
            CASE
                WHEN voucher_type IN ('sale','purchase')
                THEN 0
                ELSE 1
            END
        ")
                ->orderBy('id')
                ->get();

            \Log::info(
                'STEP 12: FIFO Ordered Transactions',
                $partyTxs->map(function ($tx) {

                    return [

                        'id'            => $tx->id,

                        'voucher_no'    => $tx->voucher_no,

                        'voucher_type'  => $tx->voucher_type,

                        'date'          => $tx->transaction_date,

                        'base_amount'   => $tx->base_amount,

                        'exchange_rate' => $tx->exchange_rate,
                    ];
                })->toArray()
            );

            DB::commit();

            \Log::info(
                'STEP 13: DB Commit Successful'
            );

            \Log::info("==================================================");
            \Log::info("FOREX UPDATE SUCCESS");
            \Log::info("==================================================");

            return redirect()
                ->route('sales.index')
                ->with(
                    'success',
                    "Transaction updated ({$updated->voucher_no})"
                );
        } catch (\Throwable $e) {

            DB::rollBack();

            \Log::error(
                'STEP ERROR: Forex transaction update failed',
                [
                    'transaction_id' => $transaction->id,

                    'payload'        => $data,

                    'error_message'  => $e->getMessage(),

                    'error_file'     => $e->getFile(),

                    'error_line'     => $e->getLine(),

                    'trace'          => $e->getTraceAsString(),
                ]
            );

            \Log::info("==================================================");
            \Log::info("FOREX UPDATE FAILED");
            \Log::info("==================================================");

            return back()
                ->withInput()
                ->withErrors([
                    'error' =>
                    'Unable to update transaction. See logs.',
                ]);
        }
    }

    /**
     * Delete transaction
     */
    public function destroy(Transaction $transaction)
    {
        if ($transaction->user_id != auth()->id()) {
            abort(403);
        }
        DB::beginTransaction();

        try {

            \Log::info('==================================================');
            \Log::info('FOREX DELETE START');
            \Log::info('==================================================');

            \Log::info('DELETE REQUEST RECEIVED', [
                'transaction_id' => $transaction->id,
                'voucher_no'     => $transaction->voucher_no,
                'voucher_type'   => $transaction->voucher_type,
                'party_id'       => $transaction->party_id,
            ]);

            /*
        |--------------------------------------------------------------------------
        | Delete via Service
        |--------------------------------------------------------------------------
        */

            $this->txService->delete($transaction);

            DB::commit();

            \Log::info('FOREX DELETE SUCCESS', [
                'transaction_id' => $transaction->id,
            ]);

            return redirect()
                ->route('sales.index')
                ->with(
                    'success',
                    'Transaction deleted successfully'
                );
        } catch (\Throwable $e) {

            DB::rollBack();

            \Log::error(
                'FOREX DELETE ERROR',
                [
                    'transaction_id' => $transaction->id,
                    'message'        => $e->getMessage(),
                    'file'           => $e->getFile(),
                    'line'           => $e->getLine(),
                    'trace'          => $e->getTraceAsString(),
                ]
            );

            return back()->withErrors([
                'error' =>
                'Unable to delete transaction. See logs.',
            ]);
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
        $to   = $request->query('to');

        $party = Party::findOrFail($partyId);
        $rows  = $this->ledgerBuilder->build($partyId, $from, $to);

        $party = Party::where('user_id', auth()->id())
            ->findOrFail($partyId);

        // compute totals
        $totals = [
            'base_dr'    => array_sum(array_column($rows, 'base_dr')),
            'base_cr'    => array_sum(array_column($rows, 'base_cr')),
            'local_dr'   => array_sum(array_column($rows, 'local_dr')),
            'local_cr'   => array_sum(array_column($rows, 'local_cr')),
            'realised'   => array_sum(array_column($rows, 'realised')),
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
            'base_amount'   => 'required|numeric|min:0',
            'exchange_rate' => 'required|numeric|min:0.00001',
        ]);

        $local = round($data['base_amount'] * $data['exchange_rate'], 4);

        return response()->json(['local_amount' => $local]);
    }

    /**
     * Validates request for create/update.
     */
    public function validateRequest(
        Request $request,
        $id = null
    ) {

        return $request->validate([

            'party_type'                      => 'nullable|string',

            'party_id'                        => 'required|exists:parties,id',

            'transaction_date'                => 'required|date',

            'base_currency_id'                => 'required|exists:currencies,id',

            'base_amount'                     => 'required|numeric|min:0.0001',

            'closing_rate'                    => 'nullable|numeric',

            'local_currency_id'               => 'required|exists:currencies,id',

            'exchange_rate'                   => 'required|numeric|min:0',

            'local_amount'                    => 'nullable|numeric|min:0',

            'voucher_type'                    => 'required|in:receipt,payment,sale,purchase',

            'voucher_no'                      => [
                'required',
                'string',
                Rule::unique('transactions', 'voucher_no')
                    ->ignore($id),
            ],

            'remarks'                         => 'nullable|string',

            'attachment'                      => 'nullable|file',

            /*
            |--------------------------------------------------------------------------
            | MANUAL MATCHES
            |--------------------------------------------------------------------------
            */

            'manual_matches'                  => 'nullable|array',

            /*
            |--------------------------------------------------------------------------
                | MANUAL MATCHES (OPTIONAL)
                |--------------------------------------------------------------------------
                |
                | Empty rows should be ignored.
                | FIFO should continue automatically.
                |
            */

            'manual_matches.*.transaction_id' => [
                'nullable',
                'exists:transactions,id',
            ],

            'manual_matches.*.amount'         => [
                'nullable',
                'numeric',
                'min:0.0001',
            ],

        ]);
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
            'party_type'    => $request->input('party_type') ?: null,
            'currency_id'   => $request->input('currency_id') ? intval($request->input('currency_id')) : null,
            'starting_date' => $startingDate, // ✅ Y-m-d
            'ending_date'   => $endingDate,   // ✅ Y-m-d
        ];

        $orderColumnIndex     = request('order.0.column');
        $orderDirection       = request('order.0.dir', 'asc');
        $opts['order_column'] = $orderColumnIndex;
        $opts['order_dir']    = $orderDirection;

        // Use LedgerBuilder to get rows (already formatted)
        $result = $this->ledgerBuilder->buildForDataTable($opts);
        $rows   = $result['rows'];
        $global = $result['global_summary'];

        // compute totals using the same logic as earlier (but from service rows)
        $totals = [
            'base_debit'            => 0.0,
            'base_credit'           => 0.0,
            'local_debit'           => 0.0,
            'local_credit'          => 0.0,
            'realised_gain'         => 0.0,
            'realised_loss'         => 0.0,
            'unrealised_gain'       => 0.0,
            'unrealised_loss'       => 0.0,
            'remaining_local_total' => 0.0,

        ];

        foreach ($rows as $r) {
            // base columns are formatted strings, so parse them
            $bd = is_numeric(str_replace(',', '', $r['base_debit'])) ? floatval(str_replace(',', '', $r['base_debit'])) : 0.0;
            $bc = is_numeric(str_replace(',', '', $r['base_credit'])) ? floatval(str_replace(',', '', $r['base_credit'])) : 0.0;
            $ld = is_numeric(str_replace(',', '', $r['local_debit'])) ? floatval(str_replace(',', '', $r['local_debit'])) : 0.0;
            $lc = is_numeric(str_replace(',', '', $r['local_credit'])) ? floatval(str_replace(',', '', $r['local_credit'])) : 0.0;

            $totals['base_debit']   += $bd;
            $totals['base_credit']  += $bc;
            $totals['local_debit']  += $ld;
            $totals['local_credit'] += $lc;

            // realised & unrealised are numeric already
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
            'realised_gain'         => round($totals['realised_gain'], 4),
            'realised_loss'         => round($totals['realised_loss'], 4),
            'unrealised_gain'       => round($totals['unrealised_gain'], 4),
            'unrealised_loss'       => round($totals['unrealised_loss'], 4),
            'final_gain_loss'       => round($finalGainLoss, 4),
            'remaining_local_total' => round($totals['remaining_local_total'], 4),

        ];

        $response  = [
            'draw'            => intval($request->input('draw', 1)),
            'recordsTotal'    => count($rows),
            'recordsFiltered' => count($rows),
            'data'            => $rows,
            'totals'          => $totals_payload,
            'global'          => $global,
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

        $orderColumnIndex     = request('order.0.column');
        $orderDirection       = request('order.0.dir', 'asc');
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

        $rows   = $built['rows'];           // ⭐ SAFE rows
        $global = $built['global_summary']; // ⭐ USE THIS
        // -----------------------------
        // 4) Compute totals
        // -----------------------------
        $totals = [
            'base_debit'      => 0.0,
            'base_credit'     => 0.0,
            'local_debit'     => 0.0,
            'local_credit'    => 0.0,
            'realised_gain'   => 0.0,
            'realised_loss'   => 0.0,
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
            $totals['base_debit']   += $bd;
            $totals['base_credit']  += $bc;
            $totals['local_debit']  += $ld;
            $totals['local_credit'] += $lc;

            // Realised & unrealised
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

        // FINAL GAIN LOSS
        $finalGainLoss  =
            ($totals['realised_gain'] - $totals['realised_loss']) +
            ($totals['unrealised_gain'] - $totals['unrealised_loss']);

        $totals_payload = [
            'realised_gain'   => round($totals['realised_gain'], 4),
            'realised_loss'   => round($totals['realised_loss'], 4),
            'unrealised_gain' => round($totals['unrealised_gain'], 4),
            'unrealised_loss' => round($totals['unrealised_loss'], 4),
            'final_gain_loss' => round($finalGainLoss, 4),
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
            'global'          => $global, // ⭐ HERE
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
                $orderColumnIndex     = request('order.0.column');
                $orderDirection       = request('order.0.dir', 'asc');
                $opts['order_column'] = $orderColumnIndex;
                $opts['order_dir']    = $orderDirection;

                $built = $this->ledgerBuilder->buildForDataTable($opts);

                $rows   = $built['rows'];           // ✅ FIX
                $global = $built['global_summary']; // ✅ FIX

            } else {

                // -------------------------------------
                // CASE 2: Specific Invoice Selected
                // -------------------------------------
                $invoice = Transaction::where('user_id', auth()->id())
                    ->find($invoiceId);

                if (! $invoice) {
                    return response()->json([
                        'draw'            => intval($request->draw),
                        'recordsTotal'    => 0,
                        'recordsFiltered' => 0,
                        'data'            => [],
                        'totals'          => [],
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
                    'starting_date'  => $start,
                    'ending_date'    => $end,
                    'allowed_tx_ids' => $allowedIds, // ⭐ important
                ];
                $orderColumnIndex     = request('order.0.column');
                $orderDirection       = request('order.0.dir', 'asc');
                $opts['order_column'] = $orderColumnIndex;
                $opts['order_dir']    = $orderDirection;

                $built = $this->ledgerBuilder->buildForDataTable($opts);

                $rows   = $built['rows'];           // ⭐ SAFE rows
                $global = $built['global_summary']; // ⭐ USE THIS
            }

            // -------------------------------------
            // FOOTER TOTALS (same as forexRemittanceData)
            // -------------------------------------
            $totals = [
                'base_debit'      => 0.0,
                'base_credit'     => 0.0,
                'local_debit'     => 0.0,
                'local_credit'    => 0.0,
                'realised_gain'   => 0.0,
                'realised_loss'   => 0.0,
                'unrealised_gain' => 0.0,
                'unrealised_loss' => 0.0,
            ];

            foreach ($rows as $r) {

                $bd = floatval(str_replace(',', '', $r['base_debit'] ?? 0));
                $bc = floatval(str_replace(',', '', $r['base_credit'] ?? 0));
                $ld = floatval(str_replace(',', '', $r['local_debit'] ?? 0));
                $lc = floatval(str_replace(',', '', $r['local_credit'] ?? 0));

                $totals['base_debit']   += $bd;
                $totals['base_credit']  += $bc;
                $totals['local_debit']  += $ld;
                $totals['local_credit'] += $lc;

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
                'totals'          => [
                    'realised_gain'   => $totals['realised_gain'],
                    'realised_loss'   => $totals['realised_loss'],
                    'unrealised_gain' => $totals['unrealised_gain'],
                    'unrealised_loss' => $totals['unrealised_loss'],
                ],
                'global'          => $global,
            ]);
        } catch (\Throwable $e) {

            \Log::error("Invoice-wise report error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Server error',
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
                'totals'          => [
                    'realised_gain'   => round($totals['realised_gain'], 4),
                    'realised_loss'   => round($totals['realised_loss'], 4),
                    'unrealised_gain' => round($totals['unrealised_gain'], 4),
                    'unrealised_loss' => round($totals['unrealised_loss'], 4),
                ],
                'global'          => $global,
            ]);
        } catch (\Throwable $e) {

            \Log::error("Currency wise report error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Server Error',
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

            $q = Transaction::where('user_id', auth()->id())
                ->whereNotNull('exchange_rate');

            if ($start && $end) {
                $q->whereBetween('transaction_date', [$start, $end]);
            }

            if (! empty($request->base_currency_id)) {
                $q->where('base_currency_id', $request->base_currency_id);
            }

            if (! empty($request->local_currency_id)) {
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
                    'rate'         => (float) $tx->exchange_rate,
                ];
            }

            // ===============================
            // 4️⃣ BUILD ROWS
            // ===============================
            $rows = [];
            $sn   = 1;

            foreach ($grouped as $date => $items) {

                $rates = array_column($items, 'rate');
                $avg   = count($rates) ? array_sum($rates) / count($rates) : 0;

                $rows[] = [
                    'sn'       => $sn++,
                    'date'     => Carbon::parse($date)->format('d-m-Y'),
                    'entries'  => array_map(function ($i) {
                        return [
                            'voucher_type' => $i['voucher_type'],
                            'voucher_no'   => $i['voucher_no'],
                            'rate'         => number_format($i['rate'], 6, '.', ''),
                        ];
                    }, $items),
                    'avg_rate' => number_format($avg, 6, '.', ''),
                ];
            }

            // ===============================
            // 5️⃣ RESPONSE
            // ===============================
            return response()->json([
                'draw'            => intval($request->draw),
                'recordsTotal'    => count($rows),
                'recordsFiltered' => count($rows),
                'data'            => $rows,
            ]);
        } catch (\Throwable $e) {

            \Log::error('[ExchangeRateReport]', [
                'msg' => $e->getMessage(),
            ]);

            return response()->json([
                'draw'            => intval($request->draw),
                'recordsTotal'    => 0,
                'recordsFiltered' => 0,
                'data'            => [],
            ], 500);
        }
    }

    public function updateManualRemark(Request $request)
    {
        try {



            $tx->manual_remark = $request->manual_remark;
            $tx->save();

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {

            \Log::error("Manual remark update error: " . $e->getMessage());

            return response()->json(['error' => true], 500);
        }
    }

    public function getOpenVouchers(Request $request)
    {
        $partyId     = $request->party_id;
        $voucherType = $request->voucher_type;

        if (! $partyId || ! $voucherType) {
            return response()->json([]);
        }

        /*
        |--------------------------------------------------------------------------
        | Determine opposite voucher types
        |--------------------------------------------------------------------------
        */

        $targetVoucherTypes = [];

        switch ($voucherType) {

            case 'receipt':
                $targetVoucherTypes = ['sale'];
                break;

            case 'sale':
                $targetVoucherTypes = ['receipt'];
                break;

            case 'payment':
                $targetVoucherTypes = ['purchase'];
                break;

            case 'purchase':
                $targetVoucherTypes = ['payment'];
                break;

            default:
                return response()->json([]);
        }

        /*
        |--------------------------------------------------------------------------
        | Fetch transactions
        |--------------------------------------------------------------------------
        */

        $transactions = \App\Models\Transaction::with([
            'matchesAsInvoice',
            'matchesAsSettlement',
        ])
            ->where('user_id', auth()->id())
            ->where('party_id', $partyId)
            ->whereIn('voucher_type', $targetVoucherTypes)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $result = [];

        foreach ($transactions as $txn) {

            /*
            |--------------------------------------------------------------------------
            | Calculate matched amount
            |--------------------------------------------------------------------------
            */

            if (in_array($txn->voucher_type, ['sale', 'purchase'])) {

                // invoice side
                $matched = (float) $txn->matchesAsInvoice->sum('matched_base');
            } else {

                // settlement side
                $matched = (float) $txn->matchesAsSettlement->sum('matched_base');
            }

            /*
            |--------------------------------------------------------------------------
            | Remaining
            |--------------------------------------------------------------------------
            */

            $remaining = round(
                (float) $txn->base_amount - $matched,
                4
            );

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $status = 'Open';

            if ($matched <= 0) {

                $status = 'Open';
            } elseif ($matched >= (float) $txn->base_amount) {

                $status = 'Settled';
            } else {

                $status = 'Partial';
            }

            /*
            |--------------------------------------------------------------------------
            | Push response
            |--------------------------------------------------------------------------
            */

            $result[] = [

                'id'               => $txn->id,

                'voucher_no'       => $txn->voucher_no,

                'voucher_type'     => ucfirst($txn->voucher_type),

                'transaction_date' => optional(
                    $txn->transaction_date
                )->format('d-m-Y'),

                'exchange_rate'    => round(
                    (float) $txn->exchange_rate,
                    4
                ),

                'base_amount'      => round(
                    (float) $txn->base_amount,
                    4
                ),

                'matched_amount'   => round(
                    $matched,
                    4
                ),

                //'remaining_amount' => round(
                //    max($remaining, 0),
                //    4
                //),
                'remaining_amount' => round(
                    $remaining,
                    4
                ),

                'status'           => $status,

                /*
            |--------------------------------------------------------------------------
            | Extra useful flags
            |--------------------------------------------------------------------------
            */

                'is_fully_settled' => $remaining <= 0,

                'is_partial'       => (
                    $matched > 0
                    &&
                    $remaining > 0
                ),

                //'can_allocate' => $remaining > 0,
                'can_allocate'     => true,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | FIFO priority
        |--------------------------------------------------------------------------
        |
        | Open first
        | Partial second
        | Settled last
        |
        */

        usort($result, function ($a, $b) {

            $priority = [
                'Open'    => 1,
                'Partial' => 2,
                'Settled' => 3,
            ];

            if (
                $priority[$a['status']]
                ==
                $priority[$b['status']]
            ) {

                return strcmp(
                    $a['transaction_date'],
                    $b['transaction_date']
                );
            }

            return $priority[$a['status']]
                <=> $priority[$b['status']];
        });

        return response()->json($result);
    }
}