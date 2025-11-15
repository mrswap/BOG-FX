<?php

namespace App\Http\Controllers;

use App\Models\ForexRemittance;
use App\Models\Party;
use App\Models\Currency;
use App\Services\ForexFifoService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use DB;

class ForexRemittanceController extends Controller
{
    protected $fifo;

    public function __construct(ForexFifoService $fifo)
    {
        $this->fifo = $fifo;
    }

    /**
     * STORE REMITTANCE (Creates remittance + creates chunk + runs FIFO)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'party_id'          => 'required|exists:parties,id',
            'transaction_date'  => 'required|date',
            'base_currency_id'  => 'required|exists:currencies,id',
            'currency_id'       => 'required|exists:currencies,id',
            'base_amount'       => 'required|numeric|min:0.0001',
            'exchange_rate'     => 'required|numeric|min:0.0001',
            'voucher_no'        => 'required|string',
            'linked_invoice_type' => 'required|in:purchase,sale,payment,receipt',
            'avg_rate'          => 'nullable|numeric',
            'closing_rate'      => 'nullable|numeric',
            'remarks'           => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {

            $rem = ForexRemittance::create([
                'party_id'          => $data['party_id'],
                'transaction_date'  => $data['transaction_date'],
                'voucher_type'      => $data['linked_invoice_type'],
                'voucher_no'        => $data['voucher_no'],
                'base_currency_id'  => $data['base_currency_id'],
                'local_currency_id' => $data['currency_id'],
                'exchange_rate'     => $data['exchange_rate'],
                'base_amount'       => $data['base_amount'],
                'local_amount'      => round($data['base_amount'] * $data['exchange_rate'], 4),
                'avg_rate'          => $data['avg_rate'] ?? null,
                'closing_rate'      => $data['closing_rate'] ?? null,
                'remarks'           => $data['remarks'] ?? null,
            ]);

            // ⬇️ CREATE FIFO CHUNK + MATCH
            $this->fifo->processRemittance($rem);

            DB::commit();
            return back()->with('success', 'Remittance saved + FIFO applied.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }


    /**
     * LEDGER FOR DATATABLES
     */
    public function forexRemittanceData(Request $request)
    {
        $party_id = $request->party_id;
        $currency_id = $request->currency_id ?? 0;
        $starting_date = $request->starting_date ?: '2000-01-01';
        $ending_date = $request->ending_date ?: now()->toDateString();
        $globalClosingRate = $request->input('closing_rate') ?? null;

        $q = ForexRemittance::with(['party', 'baseCurrency', 'localCurrency'])
            ->whereBetween('transaction_date', [$starting_date, $ending_date]);

        if ($party_id) $q->where('party_id', $party_id);
        if ($currency_id && $currency_id != 0) {
            $q->where(function ($sub) use ($currency_id) {
                $sub->where('base_currency_id', $currency_id)
                    ->orWhere('local_currency_id', $currency_id);
            });
        }

        $remittances = $q->orderBy('transaction_date', 'asc')->orderBy('id', 'asc')->get();

        $ledger = $this->buildLedgerData($remittances, $globalClosingRate);

        // ensure totals keys exist for your UI
        $totals = $ledger['totals'];
        $response = [
            "draw" => intval($request->draw),
            "recordsTotal" => $remittances->count(),
            "recordsFiltered" => $remittances->count(),
            "data" => $ledger['data'],
            "totals" => [
                'realised_gain' => $totals['realised_gain'] ?? 0,
                'realised_loss' => $totals['realised_loss'] ?? 0,
                'unrealised_gain' => $totals['unrealised_gain'] ?? 0,
                'unrealised_loss' => $totals['unrealised_loss'] ?? 0,
                'final_gain_loss' => $totals['final_gain_loss'] ?? 0,
                'total_local_debit' => $totals['total_local_debit'] ?? 0,
                'total_local_credit' => $totals['total_local_credit'] ?? 0,
            ]
        ];

        return response()->json($response);
    }


    /**
     * BUILD LEDGER DATA ROWS FROM FIFO CHUNK + ADJUSTMENTS
     */

    protected function buildLedgerData($rows, ?float $closingRate = null)
    {
        $data = [];
        $sn = 1;

        $prevRate = null;

        $totalRealised = 0;
        $totalUnreal = 0;

        foreach ($rows as $rem) {

            $baseCode  = $rem->baseCurrency?->code ?? '';
            $localCode = $rem->localCurrency?->code ?? '';

            //----------------------------------------------------
            // 1) DEBIT / CREDIT AMOUNTS
            //----------------------------------------------------
            $baseDebit = $baseCredit = $localDebit = $localCredit = 0;

            if (in_array($rem->voucher_type, ['payment', 'receipt'])) {
                // admin paid OR admin received = base_debit
                $baseDebit  = $rem->base_amount;
                $localDebit = $rem->local_amount;
            } else {
                // purchase / sale = base_credit
                $baseCredit  = $rem->base_amount;
                $localCredit = $rem->local_amount;
            }

            //----------------------------------------------------
            // 2) DIFF (Excel logic)
            //----------------------------------------------------
            if ($prevRate === null) {
                // first row → exchange - closingRate
                $rowDiff = $closingRate ? ($rem->exchange_rate - $closingRate) : 0;
            } else {
                // next rows → exchange - previous exchange
                $rowDiff = $rem->exchange_rate - $prevRate;
            }

            $prevRate = $rem->exchange_rate;

            //----------------------------------------------------
            // 3) REALISED gain/loss (sum of adjustments mapped to this voucher)
            //----------------------------------------------------
            $realised = DB::table('forex_adjustments')
                ->where('party_id', $rem->party_id)
                ->where(function ($q) use ($rem) {
                    $q->where('invoice_id', $rem->id)
                        ->orWhere('payment_id', $rem->id);
                })
                ->sum('realised_gain_loss');

            //----------------------------------------------------
            // 4) UNREALISED (from open chunks)
            //----------------------------------------------------
            $unreal = app(\App\Services\ForexFifoService::class)
                ->computeOpenUnrealised($rem, $closingRate);

            $displayRealised = floatval($realised);
            $totalRealised += $displayRealised;
            $totalUnreal    += floatval($unreal);

            //----------------------------------------------------
            // 5) Push to table
            //----------------------------------------------------
            $data[] = [
                'sn'         => $sn++,
                'date'       => $rem->transaction_date,
                'particulars' => $rem->party?->name ?? '-',

                'vch_type'   => ucfirst($rem->voucher_type),
                'vch_no'     => $rem->voucher_no,
                'exch_rate'  => number_format($rem->exchange_rate, 4),

                'base_debit'  => $baseDebit  ? number_format($baseDebit, 2) . " $baseCode"  : '',
                'base_credit' => $baseCredit ? number_format($baseCredit, 2) . " $baseCode"  : '',

                'local_debit'  => $localDebit  ? number_format($localDebit, 2) . " $localCode" : '',
                'local_credit' => $localCredit ? number_format($localCredit, 2) . " $localCode" : '',

                'avg_rate' => number_format($rem->avg_rate ?? 0, 4),
                'diff'     => number_format($rowDiff, 4),

                'realised'   => round($displayRealised, 4),
                'unrealised' => round($unreal, 4),

                'remarks' => $rem->remarks ?? '',
            ];
        }

        //----------------------------------------------------
        // 6) FINAL TOTALS LIKE YOUR REPORT
        //----------------------------------------------------
        return [
            'data' => $data,
            'totals' => [
                'realised_gain'   => $totalRealised > 0 ? $totalRealised : 0,
                'realised_loss'   => $totalRealised < 0 ? abs($totalRealised) : 0,

                'unrealised_gain' => $totalUnreal > 0 ? $totalUnreal : 0,
                'unrealised_loss' => $totalUnreal < 0 ? abs($totalUnreal) : 0,

                'final_gain_loss' => round($totalRealised + $totalUnreal, 4),
            ]
        ];
    }
}
