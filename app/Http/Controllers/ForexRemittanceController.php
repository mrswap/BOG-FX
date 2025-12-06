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

        // build filter options
        $opts = [
            'party_type' => $request->input('party_type') ?: null,
            'currency_id' => $request->input('currency_id') ? intval($request->input('currency_id')) : null,
            'starting_date' => $request->input('starting_date') ?: null,
            'ending_date' => $request->input('ending_date') ?: null,
        ];

        // Use LedgerBuilder to get rows (already formatted)
        $rows = $this->ledgerBuilder->buildForDataTable($opts);

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
        }

        $finalGainLoss = ($totals['realised_gain'] - $totals['realised_loss']) + ($totals['unrealised_gain'] - $totals['unrealised_loss']);

        $totals_payload = [
            'realised_gain' => round($totals['realised_gain'], 4),
            'realised_loss' => round($totals['realised_loss'], 4),
            'unrealised_gain' => round($totals['unrealised_gain'], 4),
            'unrealised_loss' => round($totals['unrealised_loss'], 4),
            'final_gain_loss' => round($finalGainLoss, 4),
        ];

        $response = [
            'draw' => intval($request->input('draw', 1)),
            'recordsTotal' => count($rows),
            'recordsFiltered' => count($rows),
            'data' => $rows,
            'totals' => $totals_payload,
        ];

        Log::info('[forexRemittanceData] returning rows=' . count($rows));
        return response()->json($response);
    }
}
