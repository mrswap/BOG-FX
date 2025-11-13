<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ForexRemittance;
use App\Models\ForexRate;
use App\Models\ForexGainLoss;
use App\Models\PartyPayment;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\ForexService;
use Carbon\Carbon;
use Auth;
use DB;

use Illuminate\Support\Facades\Redirect;
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
use Illuminate\Support\Facades\Log;

use Stripe\Stripe;
use NumberToWords\NumberToWords;
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

    use \App\Traits\TenantInfo;
    use \App\Traits\MailInfo;

    private $_smsModel;

    public function __construct(ISmsModel $smsModel)
    {
        $this->_smsModel = $smsModel;
    }


    public function create()
    {
        // 1️⃣ Sales related data reuse
        $lims_customer_list = Customer::with('currency')->where('is_active', true)->get();

        if (Auth::user()->role_id > 2) {
            $lims_warehouse_list = Warehouse::where([
                ['is_active', true],
                ['id', Auth::user()->warehouse_id]
            ])->get();
            $lims_biller_list = Biller::where([
                ['is_active', true],
                ['id', Auth::user()->biller_id]
            ])->get();
        } else {
            $lims_warehouse_list = Warehouse::where('is_active', true)->get();
            $lims_biller_list = Biller::where('is_active', true)->get();
        }

        $lims_tax_list = Tax::where('is_active', true)->get();
        $lims_pos_setting_data = PosSetting::latest()->first();
        $lims_reward_point_setting_data = RewardPointSetting::latest()->first();
        $currency_list = Currency::where('is_active', true)->get();
        $numberOfInvoice = Sale::count();
        $custom_fields = CustomField::where('belongs_to', 'sale')->get();
        $lims_customer_group_all = CustomerGroup::where('is_active', true)->get();

        $options = $lims_pos_setting_data ? explode(',', $lims_pos_setting_data->payment_options) : [];

        // 2️⃣ Forex-specific additional data
        $forex_suppliers = Supplier::where('is_active', true)->get();
        $forex_currencies = $currency_list; // reuse Sales currencies
        $forex_customers = $lims_customer_list; // reuse Sales customers
        $forex_warehouses = $lims_warehouse_list; // reuse Sales warehouses
        $forex_billers = $lims_biller_list; // reuse Sales billers

        $data['general_setting'] =   DB::table('general_settings')->latest()->first();

        // 3️⃣ Prepare single data array to pass to Blade
        $data = [

            // reused Sales variables
            'currency_list' => $forex_currencies,
            'lims_customer_list' => $forex_customers,
            'lims_warehouse_list' => $forex_warehouses,
            'lims_biller_list' => $forex_billers,
            'lims_pos_setting_data' => $lims_pos_setting_data,
            'lims_tax_list' => $lims_tax_list,
            'lims_reward_point_setting_data' => $lims_reward_point_setting_data,
            'options' => $options,
            'numberOfInvoice' => $numberOfInvoice,
            'custom_fields' => $custom_fields,
            'lims_customer_group_all' => $lims_customer_group_all,

            // Forex-specific
            'forex_suppliers' => $forex_suppliers,
        ];

        // 4️⃣ Return Blade view with all data
        return view('backend.forex.remittance.create', $data);
    }


    public function store(Request $request)
    {
        $request->validate([
            'party_type' => 'nullable|in:customer,supplier,both',
            'party_id' => 'required|integer',
            'currency_id' => 'required|integer',
            'base_currency_id' => 'required|integer',
            'voucher_no' => 'required|string|max:50',
            'transaction_date' => 'required|date',
            'exchange_rate' => 'required|numeric|min:0',
            'base_amount' => 'required|numeric|min:0',
            'linked_invoice_type' => 'required|in:receipt,payment,sale,purchase', // coming from form
            'closing_rate' => 'nullable|numeric|min:0',
            'avg_rate' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:255',
        ]);


        \Log::info('Storing Forex Remittance', [
            'party_id' => $request->party_id,
            'type' => $request->linked_invoice_type,
            'base_amount' => $request->base_amount,
            'rate' => $request->exchange_rate
        ]);


        DB::beginTransaction();
        try {
            $partyType = $request->party_type;
            $partyId = $request->party_id;
            $invoiceType = $request->linked_invoice_type; // ✅ map to backend field

            // 1️⃣ Prevent exact duplicate voucher for same party & invoice_type
            $exists = ForexRemittance::where('party_id', $partyId)
                ->where('voucher_no', $request->voucher_no)
                ->where('linked_invoice_type', $invoiceType)
                ->when($partyType, fn($q) => $q->where('party_type', $partyType))
                ->exists();

            if ($exists) {
                return back()->withInput()->with('error', 'Voucher already exists for this party and type.');
            }

            $baseAmount = $request->base_amount;
            $exchangeRate = $request->exchange_rate;
            $localAmount = round($baseAmount * $exchangeRate, 4);

            // 2️⃣ Create Forex Remittance entry
            $remittance = ForexRemittance::create([
                'party_type' => $partyType,
                'party_id' => $partyId,
                'currency_id' => $request->currency_id,
                'base_currency_id' => $request->base_currency_id,
                'voucher_no' => $request->voucher_no,
                'transaction_date' => $request->transaction_date,
                'exch_rate' => $exchangeRate,
                'base_amount' => $baseAmount,
                'local_amount' => $localAmount,
                'applied_base' => 0,
                'applied_local_amount' => 0,
                'realised_gain_loss' => 0,
                'unrealised_gain_loss' => 0,
                'linked_invoice_type' => $invoiceType, // ✅ renamed field
                'remarks' => $request->remarks,
                'created_by' => auth()->id(),
            ]);

            // 3️⃣ Create Party Payment record only for payment/receipt types
            if (in_array($invoiceType, ['receipt', 'payment'])) {
                PartyPayment::create([
                    'party_type' => $partyType,
                    'party_id' => $partyId,
                    'payment_reference' => $request->voucher_no,
                    'currency_id' => $request->currency_id,
                    'exchange_rate' => $exchangeRate,
                    'paid_usd' => $baseAmount,
                    'paid_local' => $localAmount,
                    'payment_mode' => 'forex',
                    'payment_date' => $request->transaction_date,
                    'created_by' => auth()->id(),
                ]);
            }

            // 4️⃣ Initialize unrealised forex gain/loss
            $forexService = app(ForexService::class);
            $forexService->initializeRemittance($remittance);

            // 5️⃣ If closing_rate provided, compute unrealised immediately
            if ($request->filled('closing_rate')) {
                $forexService->computeUnrealisedForRemittance($remittance, (float) $request->closing_rate, auth()->id());
            }

            DB::commit();

            // 6️⃣ FIFO / Ledger reconcile
            app(ForexService::class)->autoMatchRemittancesForParty($partyId, auth()->id());
            \Log::info('AutoMatch summary', app(ForexService::class)->autoMatchRemittancesForParty($partyId, auth()->id()));


            return redirect()->back()->with('success', 'Forex remittance recorded successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Forex Remittance Store Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            print_r($e->getMessage());
            die;
            return redirect()->back()->withInput()->with('error', 'Error: ' . $e->getMessage());
        }
    }




    // 6️⃣ Manual apply (if invoice applied later)
    public function applyRemittanceToInvoice(Request $request)
    {
        $request->validate([
            'remittance_id' => 'required|exists:forex_remittances,id',
            'invoice_id' => 'required|integer',
            'invoice_type' => 'required|in:sale,purchase',
            'invoice_amount' => 'required|numeric|min:0.01',
            'exchange_rate' => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();
        try {
            $remittance = ForexRemittance::lockForUpdate()->findOrFail($request->remittance_id);
            $forexService = app(ForexService::class);

            $forexService->applyInvoiceToRemittance(
                $remittance,
                $request->invoice_amount,
                $request->exchange_rate,
                auth()->id(),
                $request->invoice_id,
                $request->invoice_type
            );

            DB::commit();
            return back()->with('success', 'Remittance applied successfully. Gain/Loss recorded.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Remittance Apply Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', $e->getMessage());
        }
    }

    public function forexRemittanceData(Request $request)
    {
        $columns = [
            1 => 'transaction_date',
            2 => 'voucher_no',
            3 => 'exch_rate',
            4 => 'base_amount',
            5 => 'local_amount',
        ];

        $party_type = $request->input('party_type', 'customer');
        $party_id = $request->input('party_id');
        $currency_id = $request->input('currency_id');
        $starting_date = $request->input('starting_date', now()->subYear()->toDateString());
        $ending_date = $request->input('ending_date', now()->toDateString());

        $q = ForexRemittance::with(['currency', 'baseCurrency', 'gainLoss'])
            ->where('party_type', $party_type)
            ->whereDate('transaction_date', '>=', $starting_date)
            ->whereDate('transaction_date', '<=', $ending_date);

        if ($party_id) $q->where('party_id', $party_id);
        if ($currency_id) $q->where('currency_id', $currency_id);

        $totalData = $q->count();
        $totalFiltered = $totalData;

        $start = $request->input('start', 0);
        $limit = $request->input('length', $totalData);
        $order = 'transaction_date';
        $dir = 'asc';

        if ($request->input('order.0.column')) {
            $colIndex = $request->input('order.0.column');
            $order = $columns[$colIndex] ?? 'transaction_date';
            $dir = $request->input('order.0.dir', 'asc');
        }

        $remittances = $q->offset($start)->limit($limit)->orderBy($order, $dir)->get();
        $data = [];

        $sn = $start + 1;

        foreach ($remittances as $rem) {
            $baseCode = optional($rem->baseCurrency)->code ?? 'USD';
            $localCode = optional($rem->currency)->code ?? 'ZMW';
            $date = $rem->transaction_date
                ? \Carbon\Carbon::parse($rem->transaction_date)->format('Y-m-d')
                : \Carbon\Carbon::parse($rem->created_at)->format('Y-m-d');

            // --- Voucher Type Logic ---
            $vchType = ucfirst($rem->linked_invoice_type ?? $rem->type ?? 'N/A');
            $particulars = match ($vchType) {
                'Sale' => 'To Sale Invoice',
                'Purchase' => 'By Purchase Invoice',
                'Receipt' => 'By Customer Receipt',
                'Payment' => 'To Supplier Payment',
                default => 'Transaction'
            };
            // --- Base / Local Debit & Credit Logic ---
            // Direction is determined solely by voucher type, not party_type
            $baseDebit = $baseCredit = $localDebit = $localCredit = 0;

            switch (strtolower($vchType)) {
                case 'sale':
                case 'purchase':
                    // Invoice entries always on debit side (asset/liability)
                    $baseDebit = $rem->base_amount;
                    $localDebit = $rem->local_amount;
                    break;

                case 'receipt':
                case 'payment':
                    // Payment/Receipt entries always on credit side (settlement)
                    $baseCredit = $rem->base_amount;
                    $localCredit = $rem->local_amount;
                    break;

                default:
                    break;
            }

            // --- Gain/Loss ---
            $realised = $rem->gainLoss->where('type', 'realised')->sum('gain_loss_amount');
            $unrealised = $rem->gainLoss->where('type', 'unrealised')->sum('gain_loss_amount');

            $gainLoss = 0;
            $remarks = '-';
            $glLabel = '<span class="badge badge-secondary">-</span>';

            if ($realised != 0) {
                $gainLoss = $realised;
                $remarks = $realised > 0 ? 'Realised Gain' : 'Realised Loss';
                $color = $realised > 0 ? 'success' : 'danger';
                $sign = $realised > 0 ? '+' : '-';
                $glLabel = '<span class="badge badge-' . $color . '">' . $sign . number_format(abs($realised), 2) . '</span>';
            } elseif ($unrealised != 0) {
                $gainLoss = $unrealised;
                $remarks = $unrealised > 0 ? 'Unrealised Gain' : 'Unrealised Loss';
                $color = $unrealised > 0 ? 'info' : 'warning';
                $sign = $unrealised > 0 ? '+' : '-';
                $glLabel = '<span class="badge badge-' . $color . '">' . $sign . number_format(abs($unrealised), 2) . '</span>';
            }

            // --- Weighted Avg Rate ---
            $avgRate = $rem->closing_rate ?? $rem->exch_rate;
            $diff = round(($rem->exch_rate - $avgRate), 4);

            $data[] = [
                'sn' => $sn++,
                'date' => $date,
                'particulars' => $rem->party?->name ?? '-',

                'vch_type' => $vchType,
                'vch_no' => $rem->voucher_no ?? 'N/A',
                'exch_rate' => number_format($rem->exch_rate, 4),
                'base_debit' => $baseDebit ? number_format($baseDebit, 2) . ' ' . $baseCode : '',
                'base_credit' => $baseCredit ? number_format($baseCredit, 2) . ' ' . $baseCode : '',
                'local_debit' => $localDebit ? number_format($localDebit, 2) . ' ' . $localCode : '',
                'local_credit' => $localCredit ? number_format($localCredit, 2) . ' ' . $localCode : '',
                'avg_rate' => number_format($avgRate, 4),
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
