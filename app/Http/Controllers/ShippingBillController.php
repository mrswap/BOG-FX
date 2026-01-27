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

            // Optional but safe
            'fob_value'           => 'nullable|numeric',
            'freight'             => 'nullable|numeric',
            'insurance'           => 'nullable|numeric',
            'igst_value'          => 'nullable|numeric',
            'igst_rate'           => 'nullable|numeric',
            'ddb'                 => 'nullable|numeric',
            'rodtep'              => 'nullable|numeric',
        ]);

        $txn = Transaction::findOrFail($request->transaction_id);

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

            'igst_value'           => $request->igst_value ?? 0,
            'igst_rate'            => $request->igst_rate ?? 0,

            'ddb'                  => $request->ddb ?? 0,
            'rodtep'               => $request->rodtep ?? 0,

            'status'               => 'pending',
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
            'igst_rate'          => 'nullable|numeric',

            'ddb'                => 'nullable|numeric',
            'rodtep'             => 'nullable|numeric',

            // ✅ status validation
            'status'             => 'required|in:pending,paid',
        ]);

        $bill->update([
            'shipping_bill_no'   => $request->shipping_bill_no,
            'shipping_bill_date' => $request->shipping_bill_date,
            'port'               => $request->port,

            'fob_value'          => $request->fob_value ?? 0,
            'freight'            => $request->freight ?? 0,
            'insurance'          => $request->insurance ?? 0,

            'igst_value'         => $request->igst_value ?? 0,
            'igst_rate'          => $request->igst_rate ?? 0,

            'ddb'                => $request->ddb ?? 0,
            'rodtep'             => $request->rodtep ?? 0,

            // ✅ status update
            'status'             => $request->status,
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
