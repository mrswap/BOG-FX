<?php

namespace App\Http\Controllers;

use App\Models\ForexRemittance;
use App\Models\Party;
use Illuminate\Http\Request;
use DB;
use Carbon\Carbon;
use App\Services\ForexFifoService;

class ForexRemittanceController extends Controller
{
    protected $fifo;

    public function __construct(ForexFifoService $fifo)
    {
        $this->fifo = $fifo;
    }

    /**
     * STORE FOREX REMITTANCE
     * - Save record
     * - Run FIFO processing
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'party_id' => 'required|exists:parties,id',
            'party_type' => 'nullable|in:customer,supplier,both',
            'transaction_date' => 'required|date',
            'base_currency_id' => 'required|exists:currencies,id',
            'base_amount' => 'required|numeric|min:0.0001',
            'closing_rate' => 'nullable|numeric',
            'currency_id' => 'required|exists:currencies,id',
            'exchange_rate' => 'required|numeric',
            'linked_invoice_type' => 'required|in:receipt,payment,sale,purchase',
            'voucher_no' => 'required|string',
            'avg_rate' => 'nullable|numeric',
            'remarks' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            $remittance = ForexRemittance::create([
                'party_id'         => $data['party_id'],
                'party_type'       => $data['party_type'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'voucher_type'     => $data['linked_invoice_type'],
                'voucher_no'       => $data['voucher_no'],
                'base_currency_id' => $data['base_currency_id'],
                'local_currency_id'=> $data['currency_id'],
                'base_amount'      => (float)$data['base_amount'],
                'exchange_rate'    => (float)$data['exchange_rate'],
                'local_amount'     => round($data['base_amount'] * $data['exchange_rate'], 4),
                'avg_rate'         => $data['avg_rate'] ?? null,
                'closing_rate'     => $data['closing_rate'] ?? null,
                'diff'             => isset($data['avg_rate']) 
                                        ? round($data['exchange_rate'] - $data['avg_rate'], 6)
                                        : null,
                'remarks'          => $data['remarks'] ?? null,
            ]);

            // Apply Universal FIFO Engine
            $this->fifo->processRemittance($remittance);

            DB::commit();
            return back()->with('success', 'Forex Remittance saved and FIFO applied.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * LEDGER BUILDING (NEW SYSTEM)
     * Completely FIFO-accurate reporting
     */
    protected function buildLedgerData($remittances)
    {
        $data = [];
        $sn = 1;

        $totalRealisedGain = 0;
        $totalRealisedLoss = 0;
        $totalUnrealisedGain = 0;
        $totalUnrealisedLoss = 0;

        $openBalances = [];
        $fifo = app(\App\Services\ForexFifoService::class);

        foreach ($remittances as $rem) {

            $baseCode  = optional($rem->baseCurrency)->code ?? '';
            $localCode = optional($rem->localCurrency)->code ?? '';

            $date = Carbon::parse($rem->transaction_date)->format("Y-m-d");
            $type = strtolower($rem->voucher_type);

            // Debit/Credit
            $baseDebit = $baseCredit = $localDebit = $localCredit = 0;

            if (in_array($type, ['purchase','sale'])) {
                $baseDebit = $rem->base_amount;
                $localDebit = $rem->local_amount;

                $openBalances[$rem->base_currency_id]['open_base'] =
                    ($openBalances[$rem->base_currency_id]['open_base'] ?? 0) + $rem->base_amount;
            } else {
                $baseCredit = $rem->base_amount;
                $localCredit = $rem->local_amount;

                $openBalances[$rem->base_currency_id]['open_base'] =
                    ($openBalances[$rem->base_currency_id]['open_base'] ?? 0) - $rem->base_amount;
            }

            // Realised gain/loss from adjustments
            $realised = (float) DB::table('forex_adjustments')
                ->where(function ($q) use ($rem) {
                    $q->where('invoice_id', $rem->id)
                      ->orWhere('payment_id', $rem->id);
                })
                ->sum('realised_gain_loss');

            // Unrealised using FIFO Engine
            $unrealised = $fifo->computeUnrealised($rem);

            // Totals
            if ($realised > 0) $totalRealisedGain += $realised;
            if ($realised < 0) $totalRealisedLoss += abs($realised);

            if ($unrealised > 0) $totalUnrealisedGain += $unrealised;
            if ($unrealised < 0) $totalUnrealisedLoss += abs($unrealised);

            $data[] = [
                'sn'           => $sn++,
                'date'         => $date,
                'particulars'  => $rem->party?->name ?? "-",
                'vch_type'     => ucfirst($type),
                'vch_no'       => $rem->voucher_no,
                'exch_rate'    => number_format($rem->exchange_rate,4),

                'base_debit'   => $baseDebit  ? number_format($baseDebit,2)." ".$baseCode : "",
                'base_credit'  => $baseCredit ? number_format($baseCredit,2)." ".$baseCode : "",
                'local_debit'  => $localDebit ? number_format($localDebit,2)." ".$localCode : "",
                'local_credit' => $localCredit? number_format($localCredit,2)." ".$localCode : "",

                'avg_rate'     => number_format($rem->avg_rate ?? 0,4),
                'diff'         => number_format($rem->diff ?? 0,4),

                'realised'     => round($realised,4),
                'unrealised'   => round($unrealised,4),
                'remarks'      => $rem->remarks ?? "-"
            ];
        }

        // Add open balance rows
        foreach ($openBalances as $cid => $val) {
            $open = $val['open_base'] ?? 0;
            if ($open == 0) continue;

            $sample = $remittances->firstWhere('base_currency_id',$cid);
            $rate = $sample->exchange_rate ?? 0;
            $code = optional($sample->baseCurrency)->code ?? '';

            $data[] = [
                'sn'           => $sn++,
                'date'         => '-',
                'particulars'  => 'Open Balance',
                'vch_type'     => 'Unsettled',
                'vch_no'       => '-',
                'exch_rate'    => number_format($rate,4),
                'base_debit'   => $open > 0 ? number_format($open,2)." ".$code : '',
                'base_credit'  => $open < 0 ? number_format(abs($open),2)." ".$code : '',
                'local_debit'  => '',
                'local_credit' => '',
                'avg_rate'     => number_format($rate,4),
                'diff'         => number_format(0,4),
                'realised'     => 0,
                'unrealised'   => 0,
                'remarks'      => "Unrealised (Open)"
            ];
        }

        return [
            'data' => $data,
            'totals' => [
                'realised_gain'    => $totalRealisedGain,
                'realised_loss'    => $totalRealisedLoss,
                'unrealised_gain'  => $totalUnrealisedGain,
                'unrealised_loss'  => $totalUnrealisedLoss,
                'final_gain_loss'  => ($totalRealisedGain + $totalUnrealisedGain)
                                      - ($totalRealisedLoss + $totalUnrealisedLoss),
            ]
        ];
    }


    /**
     * AJAX LEDGER API (DataTables)
     */
    public function forexRemittanceData(Request $request)
    {
        $columns = [
            1 => 'transaction_date',
            2 => 'voucher_no',
            3 => 'exchange_rate',
            4 => 'base_amount',
            5 => 'local_amount',
        ];

        $party_id = $request->party_id;
        $currency_id = $request->currency_id ?? 0;
        $starting_date = $request->starting_date ?: '2000-01-01';
        $ending_date = $request->ending_date ?: now()->addDay()->toDateString();

        $q = ForexRemittance::with(['party','baseCurrency','localCurrency'])
            ->whereBetween('transaction_date', [$starting_date,$ending_date]);

        if ($party_id) $q->where('party_id',$party_id);

        if ($currency_id && $currency_id != 0) {
            $q->where(function($sub) use ($currency_id){
                $sub->where('base_currency_id',$currency_id)
                    ->orWhere('local_currency_id',$currency_id);
            });
        }

        $totalData = $q->count();
        $totalFiltered = $totalData;

        $start = (int) $request->start ?? 0;
        $limit = (int) $request->length ?? $totalData;

        $order = "transaction_date";
        $dir = $request->input('order.0.dir','asc');

        if ($request->input('order.0.column')) {
            $index = (int) $request->input('order.0.column');
            $order = $columns[$index] ?? 'transaction_date';
        }

        $remittances = $q->offset($start)->limit($limit)->orderBy($order,$dir)->get();

        // Use new ledger builder
        $ledger = $this->buildLedgerData($remittances);

        return response()->json([
            "draw" => intval($request->draw),
            "recordsTotal" => $totalData,
            "recordsFiltered" => $totalFiltered,
            "data" => $ledger['data'],
            "totals" => $ledger['totals']
        ]);
    }
}
