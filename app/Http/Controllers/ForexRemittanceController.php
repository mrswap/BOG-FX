<?php

namespace App\Http\Controllers;

use App\Models\ForexRemittance;
use App\Models\ForexAdjustment;
use App\Models\Party;
use App\Models\Currency;
use Illuminate\Http\Request;
use DB;

class ForexRemittanceController extends Controller
{
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
            $payload = [
                'party_id' => $data['party_id'],
                'party_type' => $data['party_type'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'voucher_type' => $data['linked_invoice_type'],
                'voucher_no' => $data['voucher_no'],
                'base_currency_id' => $data['base_currency_id'],
                'local_currency_id' => $data['currency_id'],
                'base_amount' => $data['base_amount'],
                'exchange_rate' => $data['exchange_rate'],
                'local_amount' => round($data['base_amount'] * $data['exchange_rate'], 4),
                'avg_rate' => $data['avg_rate'] ?? null,
                'closing_rate' => $data['closing_rate'] ?? null,
                'diff' => isset($data['avg_rate']) ? round($data['exchange_rate'] - $data['avg_rate'], 6) : null,
                'remarks' => $data['remarks'] ?? null
            ];

            $remittance = ForexRemittance::create($payload);
            $this->applyFifoAdjustment($remittance);
            DB::commit();

            return back()->with('success', 'Forex remittance recorded successfully.');
        } catch (\Throwable $th) {
            DB::rollBack();
            return back()->with('error', $th->getMessage());
        }
    }

    protected function applyFifoAdjustment(ForexRemittance $rem)
    {
        $partyId = $rem->party_id;
        $type = $rem->voucher_type;
        $remaining = $rem->base_amount;

        // Payment/Receipt = settle against invoices
        if (in_array($type, ['payment', 'receipt'])) {
            $targetType = $type === 'payment' ? 'purchase' : 'sale';
            $invoices = ForexRemittance::where('party_id', $partyId)
                ->where('voucher_type', $targetType)
                ->orderBy('transaction_date')
                ->get();

            foreach ($invoices as $inv) {
                if ($remaining <= 0) break;

                $used = ForexAdjustment::where('invoice_id', $inv->id)->sum('adjusted_base_amount');
                $open = $inv->base_amount - $used;
                if ($open <= 0) continue;

                $adjusted = min($remaining, $open);
                $remaining -= $adjusted;

                // Direction logic
                if ($targetType == 'purchase') {
                    $gain = ($inv->exchange_rate - $rem->exchange_rate) * $adjusted;
                } else {
                    $gain = ($rem->exchange_rate - $inv->exchange_rate) * $adjusted;
                }

                ForexAdjustment::create([
                    'party_id' => $partyId,
                    'invoice_id' => $inv->id,
                    'payment_id' => $rem->id,
                    'adjusted_base_amount' => $adjusted,
                    'adjusted_local_amount' => round($adjusted * $rem->exchange_rate, 4),
                    'realised_gain_loss' => round($gain, 4)
                ]);

                // Mark invoice if fully settled
                $sumAdj = ForexAdjustment::where('invoice_id', $inv->id)->sum('adjusted_base_amount');
                if ($sumAdj >= $inv->base_amount) {
                    $inv->gain_loss_type = 'realised';
                    $inv->gain_loss_value = ForexAdjustment::where('invoice_id', $inv->id)->sum('realised_gain_loss');
                } else {
                    $inv->gain_loss_type = 'unrealised';
                }
                $inv->save();
            }

            $rem->gain_loss_type = $remaining > 0 ? 'unrealised' : 'realised';
            $rem->gain_loss_value = ForexAdjustment::where('payment_id', $rem->id)->sum('realised_gain_loss');
            $rem->save();
        }

        // Invoice → match against advance payments
        if (in_array($type, ['sale', 'purchase'])) {
            $targetType = $type === 'purchase' ? 'payment' : 'receipt';
            $payments = ForexRemittance::where('party_id', $partyId)
                ->where('voucher_type', $targetType)
                ->orderBy('transaction_date')->get();

            foreach ($payments as $pay) {
                if ($remaining <= 0) break;
                $used = ForexAdjustment::where('payment_id', $pay->id)->sum('adjusted_base_amount');
                $open = $pay->base_amount - $used;
                if ($open <= 0) continue;

                $adjusted = min($remaining, $open);
                $remaining -= $adjusted;

                if ($type == 'purchase') {
                    $gain = ($rem->exchange_rate - $pay->exchange_rate) * $adjusted;
                } else {
                    $gain = ($pay->exchange_rate - $rem->exchange_rate) * $adjusted;
                }

                ForexAdjustment::create([
                    'party_id' => $partyId,
                    'invoice_id' => $rem->id,
                    'payment_id' => $pay->id,
                    'adjusted_base_amount' => $adjusted,
                    'adjusted_local_amount' => round($adjusted * $pay->exchange_rate, 4),
                    'realised_gain_loss' => round($gain, 4)
                ]);
            }

            $rem->gain_loss_type = $remaining > 0 ? 'unrealised' : 'realised';
            $rem->gain_loss_value = ForexAdjustment::where('invoice_id', $rem->id)->sum('realised_gain_loss');
            $rem->save();
        }
    }

    public function forexRemittanceData(Request $request)
    {
        $columns = [
            1 => 'transaction_date',
            2 => 'voucher_no',
            3 => 'exchange_rate',
            4 => 'base_amount',
            5 => 'local_amount',
        ];

        $party_type   = $request->input('party_type');
        $party_id     = $request->input('party_id');
        $currency_id  = $request->input('currency_id', 0);
        $starting_date = $request->input('starting_date');
        $ending_date   = $request->input('ending_date');

        // fallback date range (avoid empty filters)
        if (empty($starting_date) || empty($ending_date)) {
            $starting_date = '2000-01-01';
            $ending_date = now()->addDay()->toDateString();
        }

        // --- Base Query ---
        $q = \App\Models\ForexRemittance::with(['party', 'baseCurrency', 'localCurrency'])
            ->whereDate('transaction_date', '>=', $starting_date)
            ->whereDate('transaction_date', '<=', $ending_date);

        //if ($party_type) $q->where('party_type', $party_type);
        if ($party_id) $q->where('party_id', $party_id);
        if ($currency_id && $currency_id != 0) {
            $q->where(function ($sub) use ($currency_id) {
                $sub->where('base_currency_id', $currency_id)
                    ->orWhere('local_currency_id', $currency_id);
            });
        }

        $totalData = $q->count();
        $totalFiltered = $totalData;

        $start = (int) $request->input('start', 0);
        $limit = (int) $request->input('length', $totalData);
        $order = 'transaction_date';
        $dir = 'asc';

        if ($request->input('order.0.column')) {
            $colIndex = (int) $request->input('order.0.column');
            $order = $columns[$colIndex] ?? 'transaction_date';
            $dir = $request->input('order.0.dir', 'asc');
        }

        $remittances = $q->offset($start)->limit($limit)->orderBy($order, $dir)->get();
        $data = [];
        $sn = $start + 1;

        foreach ($remittances as $rem) {
            // === Basic Info ===
            $baseCode  = optional($rem->baseCurrency)->code ?? '';
            $localCode = optional($rem->localCurrency)->code ?? '';
            $date = $rem->transaction_date
                ? \Carbon\Carbon::parse($rem->transaction_date)->format('Y-m-d')
                : \Carbon\Carbon::parse($rem->created_at)->format('Y-m-d');

            // === Voucher Type and Particulars ===
            $vchTypeRaw = strtolower($rem->voucher_type ?? 'N/A');
            $vchType = ucfirst($vchTypeRaw);
            $particulars = match ($vchTypeRaw) {
                'sale'     => 'To Sale Invoice',
                'purchase' => 'By Purchase Invoice',
                'receipt'  => 'By Customer Receipt',
                'payment'  => 'To Supplier Payment',
                default    => 'Transaction'
            };

            // === Debit / Credit Mapping (Accounting rule) ===
            $baseDebit = $baseCredit = $localDebit = $localCredit = 0;
            if (in_array($vchTypeRaw, ['purchase', 'sale'])) {
                // Purchase/Sale → DEBIT (asset or expense created)
                $baseDebit = (float) $rem->base_amount;
                $localDebit = (float) $rem->local_amount;
            } elseif (in_array($vchTypeRaw, ['payment', 'receipt'])) {
                // Payment/Receipt → CREDIT (settlement)
                $baseCredit = (float) $rem->base_amount;
                $localCredit = (float) $rem->local_amount;
            }

            // === Realised Gain/Loss ===
            $realised = 0.00;
            $adjustedSum = 0.00;

            if (in_array($vchTypeRaw, ['purchase', 'sale'])) {
                $realised = (float) \DB::table('forex_adjustments')
                    ->where('invoice_id', $rem->id)
                    ->sum('realised_gain_loss');
                $adjustedSum = (float) \DB::table('forex_adjustments')
                    ->where('invoice_id', $rem->id)
                    ->sum('adjusted_base_amount');
            } else {
                $realised = (float) \DB::table('forex_adjustments')
                    ->where('payment_id', $rem->id)
                    ->sum('realised_gain_loss');
                $adjustedSum = (float) \DB::table('forex_adjustments')
                    ->where('payment_id', $rem->id)
                    ->sum('adjusted_base_amount');
            }

            // === Unrealised Gain/Loss ===
            $openBase = max(0, (float)$rem->base_amount - $adjustedSum);
            $unrealised = 0.00;

            if (($rem->gain_loss_type ?? '') === 'unrealised') {
                if (!is_null($rem->gain_loss_value) && (float)$rem->gain_loss_value != 0.0) {
                    $unrealised = (float)$rem->gain_loss_value;
                } elseif (!is_null($rem->closing_rate) && $openBase > 0) {
                    $closing = (float) $rem->closing_rate;
                    $book = (float) $rem->exchange_rate;
                    // Unrealised = (Closing - Book) * OpenBase
                    if ($vchTypeRaw === 'purchase') {
                        $unrealised = ($book - $closing) * $openBase;
                    } else {
                        $unrealised = ($closing - $book) * $openBase;
                    }
                }
            }

            // === Gain/Loss Formatting ===
            $remarks = '-';
            $glLabel = '<span class="badge badge-secondary">-</span>';

            if (abs($realised) > 0.00001) {
                $remarks = $realised > 0 ? 'Realised Gain' : 'Realised Loss';
                $color = $realised > 0 ? 'success' : 'danger';
                $sign = $realised > 0 ? '+' : '-';
                $glLabel = '<span class="badge badge-' . $color . '">' .
                    $sign . number_format(abs($realised), 2) . '</span>';
            } elseif (abs($unrealised) > 0.00001) {
                $remarks = $unrealised > 0 ? 'Unrealised Gain' : 'Unrealised Loss';
                $color = $unrealised > 0 ? 'info' : 'warning';
                $sign = $unrealised > 0 ? '+' : '-';
                $glLabel = '<span class="badge badge-' . $color . '">' .
                    $sign . number_format(abs($unrealised), 2) . '</span>';
            }

            // === Rate Difference ===
            $avgRate = $rem->avg_rate ?? $rem->closing_rate ?? $rem->exchange_rate;
            $diff = round(((float)$rem->exchange_rate - (float)$avgRate), 4);

            // === Push Row ===
            $data[] = [
                'sn' => $sn++,
                'date' => $date,
                'particulars' => $rem->party?->name ?? '-',
                'vch_type' => $vchType,
                'vch_no' => $rem->voucher_no ?? 'N/A',
                'exch_rate' => number_format((float)$rem->exchange_rate, 4),
                'base_debit'  => $baseDebit  ? number_format($baseDebit, 2) . ' ' . $baseCode : '',
                'base_credit' => $baseCredit ? number_format($baseCredit, 2) . ' ' . $baseCode : '',
                'local_debit'  => $localDebit  ? number_format($localDebit, 2) . ' ' . $localCode : '',
                'local_credit' => $localCredit ? number_format($localCredit, 2) . ' ' . $localCode : '',
                'avg_rate' => number_format((float)$avgRate, 4),
                'diff' => number_format($diff, 4),
                'gain_loss' => $glLabel,
                'remarks' => $remarks,
            ];
        }

        return response()->json([
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data
        ]);
    }
}
