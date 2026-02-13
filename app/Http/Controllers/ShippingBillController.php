<?php

namespace App\Http\Controllers;

use App\Models\ShippingBill;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Auth;

class ShippingBillController extends Controller
{
    public function index()
    {
        $bills = ShippingBill::with('transaction')->latest()->get();
        return view('backend.shipping_bill.index', compact('bills'));
    }

    public function create()
    {
        $invoices = Transaction::whereIn('voucher_type', ['sale', 'purchase'])
            ->orderBy('transaction_date', 'desc')
            ->get();

        return view('backend.shipping_bill.create', compact('invoices'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'transaction_id'      => 'required|exists:transactions,id',
            'shipping_bill_no'    => 'required|string|max:191',
            'shipping_bill_date'  => 'required|date',
            'port'                => 'required|string|max:191',

            'fob_value'           => 'nullable|numeric',
            'freight'             => 'nullable|numeric',
            'insurance'           => 'nullable|numeric',
            'igst_value'          => 'nullable|numeric',
            'taxable_amount'      => 'nullable|numeric',

            'ddb'                 => 'nullable|numeric',
            'ddb_date'            => 'nullable|date',

            'rodtep'              => 'nullable|numeric',
            'rodtep_date'         => 'nullable|date',
            'ddb_status'    => 'required|in:pending,received',
            'rodtep_status' => 'required|in:pending,received',

        ]);

        $txn = Transaction::findOrFail($request->transaction_id);

        $taxable = $request->taxable_amount ?? 0;
        $igst    = $request->igst_value ?? 0;
        $net     = $taxable + $igst;

        ShippingBill::create([
            'transaction_id'       => $txn->id,
            'export_invoice_no'    => $txn->voucher_no,
            'invoice_date'         => $txn->transaction_date,
            'usd_invoice_amount'   => $txn->base_amount,

            'shipping_bill_no'     => $request->shipping_bill_no,
            'shipping_bill_date'   => $request->shipping_bill_date,
            'port'                 => $request->port,

            'fob_value'            => $request->fob_value ?? 0,
            'freight'              => $request->freight ?? 0,
            'insurance'            => $request->insurance ?? 0,

            'igst_value'           => $igst,
            'taxable_amount'       => $taxable,
            'net_amount'           => $net,

            'ddb'                  => $request->ddb ?? 0,
            'ddb_date'             => $request->ddb_date,

            'rodtep'               => $request->rodtep ?? 0,
            'rodtep_date'          => $request->rodtep_date,

            'status'               => 'pending',
            'status_date'          => now(),

            'ddb_status'    => $request->ddb_status,
            'rodtep_status' => $request->rodtep_status,


            'created_by'           => Auth::id(),
        ]);

        return redirect()
            ->route('shipping.bill.index')
            ->with('success', 'Shipping Bill Added Successfully');
    }


    public function edit($id)
    {
        $bill = ShippingBill::findOrFail($id);
        return view('backend.shipping_bill.edit', compact('bill'));
    }

    public function update(Request $request, $id)
    {
        $bill = ShippingBill::findOrFail($id);

        $request->validate([
            'shipping_bill_no'   => 'required|string|max:191',
            'shipping_bill_date' => 'required|date',
            'port'               => 'required|string|max:191',

            'fob_value'          => 'nullable|numeric',
            'freight'            => 'nullable|numeric',
            'insurance'          => 'nullable|numeric',

            'igst_value'         => 'nullable|numeric',
            'taxable_amount'     => 'nullable|numeric',

            'ddb'                => 'nullable|numeric',
            'ddb_date'           => 'nullable|date',

            'rodtep'             => 'nullable|numeric',
            'rodtep_date'        => 'nullable|date',

            'status'             => 'required|in:pending,paid',

            'ddb_status'    => 'required|in:pending,received',
            'rodtep_status' => 'required|in:pending,received',

        ]);

        $taxable = $request->taxable_amount ?? 0;
        $igst    = $request->igst_value ?? 0;
        $net     = $taxable + $igst;

        // ✅ Only change status_date if status changed
        $statusDate = $bill->status != $request->status
            ? now()
            : $bill->status_date;

        $bill->update([
            'shipping_bill_no'   => $request->shipping_bill_no,
            'shipping_bill_date' => $request->shipping_bill_date,
            'port'               => $request->port,

            'fob_value'          => $request->fob_value ?? 0,
            'freight'            => $request->freight ?? 0,
            'insurance'          => $request->insurance ?? 0,

            'igst_value'         => $igst,
            'taxable_amount'     => $taxable,
            'net_amount'         => $net,

            'ddb'                => $request->ddb ?? 0,
            'ddb_date'           => $request->ddb_date,

            'rodtep'             => $request->rodtep ?? 0,
            'rodtep_date'        => $request->rodtep_date,

            'status'             => $request->status,
            'status_date'        => $statusDate,

            'ddb_status'    => $request->ddb_status,
            'rodtep_status' => $request->rodtep_status,

        ]);

        return redirect()
            ->route('shipping.bill.index')
            ->with('success', 'Shipping Bill Updated Successfully');
    }



    public function updateStatus(Request $request)
    {
        $request->validate([
            'id'     => 'required|exists:shipping_bills,id',
            'status' => 'required|in:pending,paid'
        ]);

        ShippingBill::where('id', $request->id)
            ->update(['status' => $request->status]);

        return response()->json(['success' => true]);
    }

    public function destroy($id)
    {
        $bill = ShippingBill::findOrFail($id);

        // Optional safety: paid record delete na ho
        // if ($bill->status === 'paid') {
        //     return redirect()->back()
        //         ->with('error', 'Paid Shipping Bill cannot be deleted');
        // }

        $bill->delete();

        return redirect()
            ->route('shipping.bill.index')
            ->with('success', 'Shipping Bill Deleted Successfully');
    }
}
