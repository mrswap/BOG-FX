<?php

namespace App\Http\Controllers;

use App\Models\ForexRemittance;
use App\Models\Party;
use Illuminate\Http\Request;
use DB;
use Carbon\Carbon;
use App\Services\ForexFifoService;

use App\Models\ForexRate;
use App\Models\ForexGainLoss;
use App\Models\Supplier;

use Illuminate\Support\Facades\Redirect;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Warehouse;
use App\Models\Biller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\Tax;
use App\Models\Sale;
use App\Models\Delivery;
use App\Models\PosSetting;
use App\Models\Product_Sale;
use App\Models\Product_Warehouse;
use App\Models\Payment;
use App\Models\Account;
use App\Models\Coupon;
use App\Models\GiftCard;
use App\Models\PaymentWithCheque;
use App\Models\PaymentWithGiftCard;
use App\Models\PaymentWithCreditCard;
use App\Models\PaymentWithPaypal;
use App\Models\User;
use App\Models\Variant;
use App\Models\ProductVariant;
use App\Models\CashRegister;
use App\Models\Returns;
use App\Models\ProductReturn;
use App\Models\Expense;
use App\Models\ProductPurchase;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\RewardPointSetting;
use App\Models\CustomField;
use App\Models\Table;
use App\Models\Courier;
use App\Models\ExternalService;
use Cache;
use App\Models\GeneralSetting;
use App\Models\MailSetting;
use Stripe\Stripe;
use NumberToWords\NumberToWords;
use Auth;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Mail\SaleDetails;
use App\Mail\LogMessage;
use App\Mail\PaymentDetails;
use Mail;
use Srmklive\PayPal\Services\ExpressCheckout;
use Srmklive\PayPal\Services\AdaptivePayments;
use GeniusTS\HijriDate\Date;
use Illuminate\Support\Facades\Validator;
use App\Models\Currency;
use App\Models\SmsTemplate;
use App\Services\SmsService;
use App\SMSProviders\TonkraSms;
use App\ViewModels\ISmsModel;
use PHPUnit\Framework\MockObject\Stub\ReturnSelf;
use Salla\ZATCA\GenerateQrCode;
use Salla\ZATCA\Tags\InvoiceDate;
use Salla\ZATCA\Tags\InvoiceTaxAmount;
use Salla\ZATCA\Tags\InvoiceTotalAmount;
use Salla\ZATCA\Tags\Seller;
use Salla\ZATCA\Tags\TaxNumber;

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
     *
     * Note: diff is computed from closing_rate when provided (global/day closing).
     * If closing_rate not provided at store-time, diff saved as null (reports compute unrealised using closing_rate passed in ledger)
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
            // If store receives a closing_rate, compute diff against exchange_rate (closing - exchange)
            $computedDiff = null;
            if (isset($data['closing_rate']) && $data['closing_rate'] !== null) {
                $computedDiff = round($data['closing_rate'] - $data['exchange_rate'], 6);
            } elseif (isset($data['avg_rate']) && $data['avg_rate'] !== null) {
                // fallback: keep diff as exchange - avg (legacy), but prefer closing_rate logic in reports
                $computedDiff = round($data['exchange_rate'] - $data['avg_rate'], 6);
            }

            $remittance = ForexRemittance::create([
                'party_id'         => $data['party_id'],
                'party_type'       => $data['party_type'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'voucher_type'     => $data['linked_invoice_type'],
                'voucher_no'       => $data['voucher_no'],
                'base_currency_id' => $data['base_currency_id'],
                'local_currency_id' => $data['currency_id'],
                'base_amount'      => (float)$data['base_amount'],
                'exchange_rate'    => (float)$data['exchange_rate'],
                'local_amount'     => round($data['base_amount'] * $data['exchange_rate'], 4),
                'avg_rate'         => $data['avg_rate'] ?? null,
                'closing_rate'     => $data['closing_rate'] ?? null,
                'diff'             => $computedDiff,
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
     * Now supports a global closing rate passed from request (closing_rate_global).
     * buildLedgerData accepts optional $closingRate (global) and uses that for diff/unrealised computations.
     */
    private function buildLedgerData($remittances, $globalClosingRate = null)
    {
        $data = [];
        $sn = 1;

        // Totals
        $totalRealisedGain = 0;
        $totalRealisedLoss = 0;
        $totalUnrealisedGain = 0;
        $totalUnrealisedLoss = 0;

        // We'll also compute net open by base_currency_id using adjustments (accurate FIFO open)
        $invoiceOpenByCurrency = []; // sum of invoice opens
        $paymentOpenByCurrency = []; // sum of payment opens

        // Service for unrealised calculation (uses same formula as FIFO service)
        $fifo = app(\App\Services\ForexFifoService::class);

        foreach ($remittances as $row) {
            // Determine effective closing rate: prefer global -> row.closing_rate -> row.avg_rate -> null
            $closingRate = $globalClosingRate ?? ($row->closing_rate ?? $row->avg_rate ?? null);

            // Sum of adjustments affecting this row
            $sumAdjAsInvoice = (float) DB::table('forex_adjustments')->where('invoice_id', $row->id)->sum('adjusted_base_amount');
            $sumAdjAsPayment = (float) DB::table('forex_adjustments')->where('payment_id', $row->id)->sum('adjusted_base_amount');

            // Realised portion (sum of realised_gain_loss from adjustments where this remittance is invoice or payment)
            $realisedSum = (float) DB::table('forex_adjustments')
                ->where(function ($q) use ($row) {
                    $q->where('invoice_id', $row->id)
                        ->orWhere('payment_id', $row->id);
                })
                ->sum('realised_gain_loss');

            // Open amount (base currency) for this remittance = base_amount - adjusted (dependent on role)
            $isInvoice = in_array(strtolower($row->voucher_type), ['purchase', 'sale']);
            $adjusted = $isInvoice ? $sumAdjAsInvoice : $sumAdjAsPayment;
            $openBase = max(0.0, (float)$row->base_amount - $adjusted);

            // Track per-currency opens for final Open Balance row(s)
            $cid = $row->base_currency_id;
            if ($isInvoice) {
                $invoiceOpenByCurrency[$cid] = ($invoiceOpenByCurrency[$cid] ?? 0) + $openBase;
            } else {
                $paymentOpenByCurrency[$cid] = ($paymentOpenByCurrency[$cid] ?? 0) + $openBase;
            }

            // Compute unrealised on the open portion using closing rate if we have it (or row->closing_rate/avg)
            $unrealised = 0.0;
            if ($openBase > 0 && !is_null($closingRate)) {
                // use service helper (ensures direction per voucher type)
                $unrealised = $fifo->computeUnrealisedWithClosing($row, $closingRate);
            }

            // Realised (as shown in ledger) = realisedSum (already from adjustments) — this may be positive or negative
            // However: your Excel-style/previous option had alternate classification; here we show realisedSum (from FIFO adjustments)
            $realised = round($realisedSum, 4);

            // Totals: aggregate realised/unrealised into gain/loss buckets
            if ($realised > 0) $totalRealisedGain += $realised;
            if ($realised < 0) $totalRealisedLoss += abs($realised);

            if ($unrealised > 0) $totalUnrealisedGain += $unrealised;
            if ($unrealised < 0) $totalUnrealisedLoss += abs($unrealised);

            // Build the debit/credit display exactly as Tally-style per column mapping
            $baseDebit = $baseCredit = $localDebit = $localCredit = "";

            // For display amounts we continue to show full voucher amounts (as in your examples)
            if ($isInvoice) {
                // purchase/sale => base debit + local debit
                $baseDebit = number_format($row->base_amount, 2) . " " . optional($row->baseCurrency)->code;
                $localDebit = number_format($row->local_amount, 2) . " " . optional($row->localCurrency)->code;
            } else {
                // payment/receipt => base credit + local credit
                $baseCredit = number_format($row->base_amount, 2) . " " . optional($row->baseCurrency)->code;
                $localCredit = number_format($row->local_amount, 2) . " " . optional($row->localCurrency)->code;
            }

            // Diff to show = closing - exchange (if closing known), else 0 (or row->diff if you prefer)
            $diff = is_null($closingRate) ? round($row->diff ?? 0, 4) : round($closingRate - $row->exchange_rate, 4);

            // Remarks: decide based on whether openBase > 0
            $remarks = $openBase > 0 ? 'Unrealised (Open)' : '-';

            $data[] = [
                'sn'           => $sn++,
                'date'         => $row->transaction_date,
                'particulars'  => $row->party->name ?? '-',
                'vch_type'     => ucfirst($row->voucher_type),
                'vch_no'       => $row->voucher_no,
                'exch_rate'    => number_format($row->exchange_rate, 4),
                'base_debit'   => $baseDebit,
                'base_credit'  => $baseCredit,
                'local_debit'  => $localDebit,
                'local_credit' => $localCredit,
                'avg_rate'     => number_format($row->avg_rate ?? 0, 4),
                'diff'         => number_format($diff, 4),
                'realised'     => round($realised, 4),
                'unrealised'   => round($unrealised, 4),
                'remarks'      => $remarks,
            ];
        }

        // Build Open Balance rows per currency using net = invoiceOpen - paymentOpen
        foreach ($invoiceOpenByCurrency as $cid => $val) {
            $paymentOpen = $paymentOpenByCurrency[$cid] ?? 0;
            $netOpen = round($val - $paymentOpen, 6); // positive => invoice excess (debit), negative => payment excess (credit)

            if (abs($netOpen) < 0.000001) continue;

            // pick a sample remittance for currency context
            $sample = $remittances->firstWhere('base_currency_id', $cid);
            $sampleRate = $sample->exchange_rate ?? 0;
            $code = optional($sample->baseCurrency)->code ?? '';

            $closingRate = $globalClosingRate ?? ($sample->closing_rate ?? $sample->avg_rate ?? null);
            $diffForOpen = is_null($closingRate) ? 0 : round($closingRate - $sampleRate, 4);

            if ($netOpen > 0) {
                // net open on invoice side => show as base debit (unsettled invoices)
                $baseDebit = number_format($netOpen, 2) . " " . $code;
                $baseCredit = "";
                $remarks = "Unrealised (Open)";
            } else {
                // net open on payment side => show as base credit (unsettled payments/advances)
                $baseDebit = "";
                $baseCredit = number_format(abs($netOpen), 2) . " " . $code;
                $remarks = "Unrealised (Open)";
            }

            // unrealised on open = netOpen * diff (direction must match invoice vs payment)
            $openUnrealised = round($netOpen * ($closingRate - $sampleRate), 4);

            if ($openUnrealised > 0) $totalUnrealisedGain += $openUnrealised;
            if ($openUnrealised < 0) $totalUnrealisedLoss += abs($openUnrealised);

            $data[] = [
                'sn'           => $sn++,
                'date'         => '-',
                'particulars'  => 'Open Balance',
                'vch_type'     => 'Unsettled',
                'vch_no'       => '-',
                'exch_rate'    => number_format($sampleRate, 4),
                'base_debit'   => $baseDebit,
                'base_credit'  => $baseCredit,
                'local_debit'  => '',
                'local_credit' => '',
                'avg_rate'     => number_format($sampleRate, 4),
                'diff'         => number_format($diffForOpen, 4),
                'realised'     => 0,
                'unrealised'   => round($openUnrealised, 4),
                'remarks'      => $remarks,
            ];
        }

        // Totals
        $final = ($totalRealisedGain - $totalRealisedLoss) + ($totalUnrealisedGain - $totalUnrealisedLoss);

        return [
            'data' => $data,
            'totals' => [
                'realised_gain'    => $totalRealisedGain,
                'realised_loss'    => $totalRealisedLoss,
                'unrealised_gain'  => $totalUnrealisedGain,
                'unrealised_loss'  => $totalUnrealisedLoss,
                'final_gain_loss'  => $final,
            ],
        ];
    }

    /**
     * AJAX LEDGER API (DataTables) — accepts closing_rate_global from request (Option A).
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

        $q = ForexRemittance::with(['party', 'baseCurrency', 'localCurrency'])
            ->whereBetween('transaction_date', [$starting_date, $ending_date]);

        if ($party_id) $q->where('party_id', $party_id);

        if ($currency_id && $currency_id != 0) {
            $q->where(function ($sub) use ($currency_id) {
                $sub->where('base_currency_id', $currency_id)
                    ->orWhere('local_currency_id', $currency_id);
            });
        }

        $totalData = $q->count();
        $totalFiltered = $totalData;

        $start = (int) $request->start ?? 0;
        $limit = (int) $request->length ?? $totalData;

        $order = "transaction_date";
        $dir = $request->input('order.0.dir', 'asc');

        if ($request->input('order.0.column')) {
            $index = (int) $request->input('order.0.column');
            $order = $columns[$index] ?? 'transaction_date';
        }

        $remittances = $q->offset($start)->limit($limit)->orderBy($order, $dir)->get();

        // Use new ledger builder with a global closing rate passed from the request (closing_rate_global)
        $closingRate = $request->input('closing_rate_global'); // expected numeric or null
        $ledger = $this->buildLedgerData($remittances, $closingRate);

        return response()->json([
            "draw" => intval($request->draw),
            "recordsTotal" => $totalData,
            "recordsFiltered" => $totalFiltered,
            "data" => $ledger['data'],
            "totals" => $ledger['totals']
        ]);
    }

    // small helper to keep isInvoiceType logic same as service (can be reused)
    protected function isInvoiceType(string $vchType): bool
    {
        return in_array(strtolower($vchType), ['purchase', 'sale']);
    }

    // ... The rest of the file (report method etc.) remains unchanged or can be left as is.

    public function report(Request $request, $type)
    {
        if ($request->method() == "GET") {

            $role = Role::find(1);
            if ($role->hasPermissionTo('sales-index')) {
                $permissions = Role::findByName($role->name)->permissions;

                $role_has_permissions_list = Cache::remember('role_has_permissions_list' . 1, 60 * 60 * 24 * 365, function () {
                    return DB::table('permissions')->join('role_has_permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('role_id', 1)->select('permissions.name')->get();
                });
                foreach ($permissions as $permission)
                    $all_permission[] = $permission->name;
                if (empty($all_permission))
                    $all_permission[] = 'dummy text';

                if ($request->input('warehouse_id'))
                    $warehouse_id = $request->input('warehouse_id');
                else
                    $warehouse_id = 0;

                if ($request->input('sale_status'))
                    $sale_status = $request->input('sale_status');
                else
                    $sale_status = 0;

                if ($request->input('payment_status'))
                    $payment_status = $request->input('payment_status');
                else
                    $payment_status = 0;

                if ($request->input('sale_type'))
                    $sale_type = $request->input('sale_type');
                else
                    $sale_type = 0;

                if ($request->input('payment_method'))
                    $payment_method = $request->input('payment_method');
                else
                    $payment_method = 0;

                if ($request->input('starting_date')) {
                    $starting_date = $request->input('starting_date');
                    $ending_date = $request->input('ending_date');
                } else {
                    $starting_date = date("Y-m-d", strtotime(date('Y-m-d', strtotime('-1 year', strtotime(date('Y-m-d'))))));
                    $ending_date = date("Y-m-d");
                }

                $lims_gift_card_list = GiftCard::where("is_active", true)->get();
                $lims_pos_setting_data = PosSetting::latest()->first();
                $lims_reward_point_setting_data = RewardPointSetting::latest()->first();
                $lims_warehouse_list = Warehouse::where('is_active', true)->get();
                $lims_account_list = Account::where('is_active', true)->get();
                $lims_courier_list = Courier::where('is_active', true)->get();
                if ($lims_pos_setting_data)
                    $options = explode(',', $lims_pos_setting_data->payment_options);
                else
                    $options = [];
                $numberOfInvoice = Sale::count();
                $custom_fields = CustomField::where([
                    ['belongs_to', 'sale'],
                    ['is_table', true]
                ])->pluck('name');
                $field_name = [];
                foreach ($custom_fields as $fieldName) {
                    $field_name[] = str_replace(" ", "_", strtolower($fieldName));
                }
                $smsTemplates = SmsTemplate::all();
                $currency_list = Currency::where('is_active', true)->get();
                $currency_list = Currency::where('is_active', true)->get();

                $party_type = $request->input('party_type', 'customer'); // customer or supplier
                $starting_date = $request->input('starting_date', now()->subMonth()->toDateString());
                $ending_date = $request->input('ending_date', now()->toDateString());

                $general_setting = GeneralSetting::latest()->first();

                $theme = 'light';

                $reportData = [];


                return view('backend.forex_reports.unified', compact('role_has_permissions_list', 'theme', 'general_setting', 'starting_date', 'ending_date', 'warehouse_id', 'sale_status', 'payment_status', 'sale_type', 'payment_method', 'lims_gift_card_list', 'lims_pos_setting_data', 'lims_reward_point_setting_data', 'lims_account_list', 'lims_warehouse_list', 'all_permission', 'options', 'numberOfInvoice', 'custom_fields', 'field_name', 'lims_courier_list', 'smsTemplates', 'currency_list', 'reportData', 'type'));
            }
        }

        // ====================
        // FILTERS
        // ====================
        $startDate = $request->starting_date ?? '2000-01-01';
        $endDate   = $request->ending_date ?? now()->toDateString();

        $q = ForexRemittance::with(['party', 'baseCurrency', 'localCurrency'])
            ->whereBetween('transaction_date', [$startDate, $endDate]);

        // ====================
        // APPLY TYPE FILTERS
        // ====================
        switch ($type) {

            case 'invoice':          // sale + purchase
                $q->whereIn('voucher_type', ['sale', 'purchase']);
                break;

            case 'party':            // party wise
                if ($request->party_id) {
                    $q->where('party_id', $request->party_id);
                }
                break;

            case 'base':             // base currency wise
                if ($request->currency_id) {
                    $q->where('base_currency_id', $request->currency_id);
                }
                break;

            case 'local':            // local currency wise
                if ($request->currency_id) {
                    $q->where('local_currency_id', $request->currency_id);
                }
                break;

            case 'realised':         // realised GL only
                $q->where('gain_loss_type', 'realised');
                break;

            case 'unrealised':       // unrealised GL only
                $q->where('gain_loss_type', 'unrealised');
                break;
        }

        // ====================
        // PAGINATION FOR DATATABLES
        // ====================
        $totalData = $q->count();
        $start = (int) $request->start ?? 0;
        $limit = (int) $request->length ?? $totalData;

        $remittances = $q
            ->offset($start)
            ->limit($limit)
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // ==== USE EXISTING LEDGER BUILDER FROM MAIN CONTROLLER ====
        $ledgerCtrl = new \App\Http\Controllers\ForexRemittanceController($this->fifo);
        $ledger = $ledgerCtrl->buildLedgerData($remittances);

        return response()->json([
            "draw" => intval($request->draw),
            "recordsTotal" => $totalData,
            "recordsFiltered" => $totalData,
            "data" => $ledger['data'],
            "totals" => $ledger['totals']
        ]);
    }
}
