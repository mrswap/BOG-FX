<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Purchase;
use App\Models\CashRegister;
use App\Models\Account;
use App\Models\Payment;
use App\Models\Party;
use App\Models\MailSetting;
use Illuminate\Validation\Rule;
use Auth;
use DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Mail\SupplierCreate;
use App\Mail\CustomerCreate;
use Mail;
use Twilio\TwiML\Voice\Pay;

class SupplierController extends Controller
{
    use \App\Traits\MailInfo;

    public function index()
    {
        $role = Role::find(Auth::user()->role_id);
        if ($role->hasPermissionTo('suppliers-index')) {
            $permissions = Role::findByName($role->name)->permissions;
            foreach ($permissions as $permission)
                $all_permission[] = $permission->name;
            if (empty($all_permission))
                $all_permission[] = 'dummy text';
            $lims_supplier_all = Supplier::where('is_active', true)->get();
            $parties = Party::all();
            return view('backend.supplier.index', compact('lims_supplier_all', 'all_permission', 'parties'));
        } else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    public function clearDue(Request $request)
    {
        $lims_due_purchase_data = Purchase::select('id', 'warehouse_id', 'grand_total', 'paid_amount', 'payment_status')
            ->where([
                ['payment_status', 1],
                ['supplier_id', $request->supplier_id]
            ])->get();
        $total_paid_amount = $request->amount;
        foreach ($lims_due_purchase_data as $key => $purchase_data) {
            if ($total_paid_amount == 0)
                break;
            $due_amount = $purchase_data->grand_total - $purchase_data->paid_amount;
            $lims_cash_register_data =  CashRegister::select('id')
                ->where([
                    ['user_id', Auth::id()],
                    ['warehouse_id', $purchase_data->warehouse_id],
                    ['status', 1]
                ])->first();
            if ($lims_cash_register_data)
                $cash_register_id = $lims_cash_register_data->id;
            else
                $cash_register_id = null;
            $account_data = Account::select('id')->where('is_default', 1)->first();
            if ($total_paid_amount >= $due_amount) {
                $paid_amount = $due_amount;
                $payment_status = 2;
            } else {
                $paid_amount = $total_paid_amount;
                $payment_status = 1;
            }
            Payment::create([
                'payment_reference' => 'ppr-' . date("Ymd") . '-' . date("his"),
                'purchase_id' => $purchase_data->id,
                'user_id' => Auth::id(),
                'cash_register_id' => $cash_register_id,
                'account_id' => $account_data->id,
                'amount' => $paid_amount,
                'change' => 0,
                'paying_method' => 'Cash',
                'payment_note' => $request->note
            ]);
            $purchase_data->paid_amount += $paid_amount;
            $purchase_data->payment_status = $payment_status;
            $purchase_data->save();
            $total_paid_amount -= $paid_amount;
        }
        return redirect()->back()->with('message', 'Due cleared successfully');
    }

    public function create()
    {
        $role = Role::find(Auth::user()->role_id);
        if ($role->hasPermissionTo('suppliers-add')) {
            $lims_customer_group_all = CustomerGroup::where('is_active', true)->get();
            return view('backend.supplier.create', compact('lims_customer_group_all'));
        } else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'type' => 'required|in:customer,supplier,both',
            'name' => 'required|max:255',
            'company_name' => [
                'required',
                'max:255',
                Rule::unique('parties')->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('parties')->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
            'phone' => 'required|max:50',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:10000',
        ]);

        $data = $request->except('image');
        $data['is_active'] = 1;

        // Handle image upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $ext = $image->getClientOriginalExtension();
            $imageName = preg_replace('/[^a-zA-Z0-9]/', '', $request->company_name) . '.' . $ext;
            $image->move(public_path('images/party'), $imageName);
            $data['image'] = $imageName;
        }

        // Save new Party
        $party = Party::create($data);

        return redirect()->route('supplier.index')->with('message', 'Party created successfully!');
    }

    public function edit($id)
    {
        $role = Role::find(Auth::user()->role_id);
        if ($role->hasPermissionTo('suppliers-edit')) {
            $lims_supplier_data = Supplier::where('id', $id)->first();
            $party = Party::where('id', $id)->first();
            return view('backend.supplier.edit', compact('lims_supplier_data', 'party'));
        } else
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
    }
    public function update(Request $request, $id)
    {
        $this->validate($request, [
            'type' => 'required|in:customer,supplier,both',
            'name' => 'required|max:255',
            'company_name' => [
                'required',
                'max:255',
                Rule::unique('parties')->ignore($id)->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('parties')->ignore($id)->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
            'phone' => 'required|max:50',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:10000',
        ]);

        $party = Party::findOrFail($id);
        $input = $request->except('image');

        // Replace old image if new uploaded
        if ($request->hasFile('image')) {
            $oldImage = public_path('images/party/' . $party->image);
            if ($party->image && file_exists($oldImage)) {
                unlink($oldImage);
            }
            $image = $request->file('image');
            $ext = $image->getClientOriginalExtension();
            $imageName = preg_replace('/[^a-zA-Z0-9]/', '', $request->company_name) . '.' . $ext;
            $image->move(public_path('images/party'), $imageName);
            $input['image'] = $imageName;
        }

        $party->update($input);

        return redirect()->route('supplier.index')->with('message', 'Party updated successfully!');
    }


    public function deleteBySelection(Request $request)
    {
        $supplier_id = $request['supplierIdArray'];
        foreach ($supplier_id as $id) {
            $lims_supplier_data = Supplier::findOrFail($id);
            $lims_supplier_data->is_active = false;
            $lims_supplier_data->save();
            $this->fileDelete(public_path('images/supplier/'), $lims_supplier_data->image);
        }
        return 'Supplier deleted successfully!';
    }

    public function destroy($id)
    {
        $lims_supplier_data = Supplier::findOrFail($id);
        $lims_supplier_data->is_active = false;
        $lims_supplier_data->save();
        $this->fileDelete(public_path('images/supplier/'), $lims_supplier_data->image);

        return redirect('supplier')->with('not_permitted', 'Data deleted successfully');
    }

    public function importSupplier(Request $request)
    {
        $upload = $request->file('file');
        $ext = pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION);
        if ($ext != 'csv')
            return redirect()->back()->with('not_permitted', 'Please upload a CSV file');
        $filename =  $upload->getClientOriginalName();
        $filePath = $upload->getRealPath();
        //open and read
        $file = fopen($filePath, 'r');
        $header = fgetcsv($file);
        $escapedHeader = [];
        //validate
        foreach ($header as $key => $value) {
            $lheader = strtolower($value);
            $escapedItem = preg_replace('/[^a-z]/', '', $lheader);
            array_push($escapedHeader, $escapedItem);
        }
        //looping through othe columns
        while ($columns = fgetcsv($file)) {
            if ($columns[0] == "")
                continue;
            foreach ($columns as $key => $value) {
                $value = preg_replace('/\D/', '', $value);
            }
            $data = array_combine($escapedHeader, $columns);

            $supplier = Supplier::firstOrNew(['company_name' => $data['companyname']]);
            $supplier->name = $data['name'];
            $supplier->image = $data['image'];
            $supplier->vat_number = $data['vatnumber'];
            $supplier->email = $data['email'];
            $supplier->phone_number = $data['phonenumber'];
            $supplier->address = $data['address'];
            $supplier->city = $data['city'];
            $supplier->state = $data['state'];
            $supplier->postal_code = $data['postalcode'];
            $supplier->country = $data['country'];
            $supplier->is_active = true;
            $supplier->save();
            $message = 'Supplier Imported Successfully';

            $mail_setting = MailSetting::latest()->first();


            if ($data['email'] && $mail_setting) {
                try {
                    Mail::to($data['email'])->send(new SupplierCreate($data));
                } catch (\Excetion $e) {
                    $message = 'Supplier imported successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
                }
            }
        }
        return redirect('supplier')->with('message', $message);
    }

    public function suppliersAll()
    {
        $lims_supplier_list = DB::table('suppliers')->where('is_active', true)->get();

        $html = '';
        foreach ($lims_supplier_list as $supplier) {
            $html .= '<option value="' . $supplier->id . '">' . $supplier->name . ' (' . $supplier->phone_number . ')' . '</option>';
        }

        return response()->json($html);
    }
}
