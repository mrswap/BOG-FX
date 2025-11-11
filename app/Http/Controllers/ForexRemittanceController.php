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
            'party_type'       => 'required|in:customer,supplier',
            'party_id'         => 'required|integer|exists:' . ($request->party_type === 'customer' ? 'customers' : 'suppliers') . ',id',
            'currency_id'      => 'required|integer|exists:currencies,id',
            'base_currency_id' => 'required|integer|exists:currencies,id',
            'voucher_no'       => 'required|string|max:50|unique:forex_remittances,voucher_no',
            'transaction_date' => 'required|date',
            'exchange_rate'    => 'required|numeric|min:0',
            'invoice_amount'   => 'required|numeric|min:0',
            'type'             => 'required|in:receipt,payment',
            'status'           => 'nullable|string|max:50',
            'remarks'          => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            $baseAmount   = $request->invoice_amount;
            $exchangeRate = $request->exchange_rate;
            $localAmount  = round($baseAmount * $exchangeRate, 4);

            // 1️⃣ Create Forex Remittance
            $remittance = ForexRemittance::create([
                'party_type'           => $request->party_type,
                'party_id'             => $request->party_id,
                'currency_id'          => $request->currency_id,
                'base_currency_id'     => $request->base_currency_id,
                'voucher_no'           => $request->voucher_no,
                'transaction_date'     => $request->transaction_date,
                'exch_rate'            => $exchangeRate,
                'base_amount'          => $baseAmount,
                'local_amount'         => $localAmount,
                'invoice_amount'       => $baseAmount,
                'closing_rate'         => null,
                'realised_gain_loss'   => 0,
                'unrealised_gain_loss' => 0,
                'applied_base'         => 0,
                'linked_invoice_type'  => null,
                'linked_invoice_id'    => null,
                'remarks'              => $request->remarks,
                'type'                 => $request->type,
                'status'               => $request->status ?? 'pending',
                'created_by'           => auth()->id(),
            ]);

            // 2️⃣ Create Party Payment
            $payment = PartyPayment::create([
                'party_type'          => $request->party_type,
                'party_id'            => $request->party_id,
                'payment_reference'   => $request->voucher_no,
                'related_invoice_id'  => null,
                'related_invoice_type' => null,
                'currency_id'         => $request->currency_id,
                'exchange_rate'       => $exchangeRate,
                'paid_usd'            => $baseAmount,
                'paid_local'          => $localAmount,
                'payment_mode'        => 'forex',
                'remarks'             => $request->remarks,
                'payment_date'        => $request->transaction_date,
                'created_by'          => auth()->id(),
            ]);

            // 3️⃣ Initialize unrealised gain/loss
            $forexService = app(\App\Services\ForexService::class);
            $forexService->initializeRemittance($remittance);

            // 4️⃣ Auto-apply to existing invoices (prepayment scenario)
            $remainingBase = $baseAmount;

            $invoices = \App\Models\Invoice::where('party_id', $request->party_id)
                ->where('status', '!=', 'paid')
                ->orderBy('transaction_date', 'asc')
                ->get();

            foreach ($invoices as $invoice) {
                if ($remainingBase <= 0) break;

                $toApply = min($remainingBase, $invoice->due_amount);

                // Call internal method to apply remittance to invoice
                $applyResult = $forexService->linkRemittanceToParty(
                    $remittance,
                    $invoice->id,
                    $invoice->type,
                    $toApply,
                    $exchangeRate,
                    auth()->id()
                );

                $remainingBase -= $toApply;
            }

            // 5️⃣ Update remittance with applied payment
            $remittance->update([
                'party_payment_id' => $payment->id,
                'status'           => $remainingBase <= 0 ? 'realised' : 'partial',
            ]);

            DB::commit();

            return redirect()
                ->route('sales.create')
                ->with('success', 'Forex remittance, payment, and gain/loss recorded successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Forex Remittance Store Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Error creating remittance: ' . $e->getMessage());
        }
    }


    public function applyRemittanceToInvoice(Request $request)
    {
        $request->validate([
            'remittance_id'  => 'required|exists:forex_remittances,id',
            'invoice_id'     => 'required|integer',
            'invoice_type'   => 'required|in:sale,purchase',
            'invoice_amount' => 'required|numeric|min:0.01',
            'exchange_rate'  => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $remittance = ForexRemittance::lockForUpdate()->findOrFail($request->remittance_id);
            $forexService = app(\App\Services\ForexService::class);

            $invoiceAmount = $request->invoice_amount;
            $remBase = $remittance->remaining_base ?? $remittance->base_amount;

            // 🔹 Amount to apply
            $appliedBase = min($invoiceAmount, $remBase);

            // ⚙️ Apply to invoice
            $result = $forexService->linkRemittanceToParty(
                $remittance,
                $request->invoice_id,
                $request->invoice_type,
                $appliedBase,
                $request->exchange_rate,
                auth()->id()
            );

            // ⚙️ Update remittance applied/remaining
            $remittance->update([
                'applied_base'   => $remittance->applied_base + $appliedBase,
                'remaining_base' => max(0, $remBase - $appliedBase),
                'realised_gain_loss' => DB::raw("realised_gain_loss + {$result['realised_gain_loss']}")
            ]);

            // 🔹 Handle extra payment as new remittance
            $extraAmount = $invoiceAmount - $appliedBase;
            if ($extraAmount > 0) {
                $extraRemittance = ForexRemittance::create([
                    'party_type' => $remittance->party_type,
                    'party_id'   => $remittance->party_id,
                    'currency_id' => $remittance->currency_id,
                    'base_currency_id' => $remittance->base_currency_id,
                    'voucher_no' => $remittance->voucher_no . '-EXTRA-' . now()->format('YmdHis'),
                    'transaction_date' => now()->toDateString(),
                    'exch_rate' => $request->exchange_rate,
                    'base_amount' => $extraAmount,
                    'local_amount' => round($extraAmount * $request->exchange_rate, 4),
                    'invoice_amount' => $extraAmount,
                    'closing_rate' => null,
                    'realised_gain_loss' => 0,
                    'unrealised_gain_loss' => 0,
                    'applied_local_amount' => 0,
                    'linked_invoice_type' => null,
                    'linked_invoice_id'   => null,
                    'remarks' => 'Extra payment from invoice #' . $request->invoice_id,
                    'type' => $remittance->type,
                    'status' => 'pending',
                    'created_by' => auth()->id(),
                ]);

                // Initialize unrealised gain/loss
                $forexService->initializeRemittance($extraRemittance);
            }

            DB::commit();

            return back()->with('success', 'Remittance applied successfully. Gain/Loss recorded.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Remittance Apply Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Error applying remittance: ' . $e->getMessage());
        }
    }




    public function forexRemittanceData(Request $request)
    {
        $columns = [
            1 => 'transaction_date',
            2 => 'reference_no',
            3 => 'usd_amount',
            4 => 'local_amount',
        ];

        $party_type = $request->input('party_type', 'customer'); // customer or supplier
        $party_id = $request->input('party_id');
        $currency_id = $request->input('currency_id');
        $starting_date = $request->input('starting_date', now()->subYear()->toDateString());
        $ending_date = $request->input('ending_date', now()->toDateString());

        $q = ForexRemittance::with(['currency', 'customer', 'supplier'])
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
        $dir = 'desc';

        if ($request->input('order.0.column')) {
            $colIndex = $request->input('order.0.column');
            $order = $columns[$colIndex] ?? 'transaction_date';
            $dir = $request->input('order.0.dir', 'desc');
        }

        $remittances = $q->offset($start)->limit($limit)->orderBy($order, $dir)->get();
        $data = [];

        foreach ($remittances as $key => $rem) {
            // Party name dynamically
            $partyName = $party_type === 'customer'
                ? ($rem->customer ? $rem->customer->name : 'N/A')
                : ($rem->supplier ? $rem->supplier->name : 'N/A');

            // Base currency
            $baseCurrency = $rem->baseCurrency;
            $baseCode = $baseCurrency ? $baseCurrency->code : 'N/A';

            // Remittance currency
            $curCode = $rem->currency ? $rem->currency->code : 'N/A';

            // Realised / Unrealised gain/loss
            $gainLoss = $rem->gainLoss()->sum('gain_loss_amount');

            $nestedData = [
                'id' => $rem->id,
                'key' => $key,
                'transaction_date' => $rem->transaction_date
                    ? $rem->transaction_date->format('Y-m-d') // or use config('date_format')
                    : $rem->created_at->format('Y-m-d'),

                'reference_no' => $rem->reference_no,
                'party' => $partyName,
                'currency' => $curCode,
                'usd_amount' => number_format($rem->usd_amount, config('decimal')) . ' ' . $curCode,
                'local_amount' => number_format($rem->local_amount, config('decimal')) . ' ' . $baseCode,
                'exchange_rate' => number_format($rem->exch_rate, 4),
                'gain_loss' => number_format($gainLoss, config('decimal')),
                'remarks' => $rem->remarks ?? '-',
                'options' => '<div class="btn-group">
                <a href="' . route('sales.edit', $rem->id) . '" class="btn btn-sm btn-primary">Edit</a>
                <form action="' . route('sales.destroy', $rem->id) . '" method="POST" style="display:inline-block;">' .
                    csrf_field() . method_field('DELETE') .
                    '<button type="submit" class="btn btn-sm btn-danger" onclick="return confirm(\'Are you sure?\')">Delete</button>
                </form>
            </div>',
            ];

            $data[] = $nestedData;
        }

        return response()->json([
            "draw" => intval($request->input('draw')),
            "recordsTotal" => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data" => $data
        ]);
    }
}
