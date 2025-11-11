<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ForexRemittance;
use App\Models\ForexRate;
use App\Models\ForexGainLoss;
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
}
