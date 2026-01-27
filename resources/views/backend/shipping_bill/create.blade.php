@extends('backend.layout.main')

@section('content')
<div class="card">
    <div class="card-header">
        <h4>Create Shipping Bill</h4>
    </div>

    <div class="card-body">
        <form method="POST" action="{{ route('shipping.bill.store') }}">
            @csrf

            {{-- Invoice Selection --}}
            <div class="row">
                <div class="col-md-4">
                    <label>Export Invoice *</label>
                    <select name="transaction_id" class="form-control selectpicker" data-live-search="true" required>
                        @foreach ($invoices as $inv)
                            <option value="{{ $inv->id }}">
                                {{ $inv->voucher_no }} | {{ $inv->transaction_date }} | USD {{ $inv->base_amount }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label>Shipping Bill No *</label>
                    <input type="text" name="shipping_bill_no" class="form-control" required>
                </div>

                <div class="col-md-4">
                    <label>Shipping Bill Date *</label>
                    <input type="date" name="shipping_bill_date" class="form-control" required>
                </div>
            </div>

            {{-- Port & FOB --}}
            <div class="row mt-3">
                <div class="col-md-4">
                    <label>Port *</label>
                    <input type="text" name="port" class="form-control" required>
                </div>

                <div class="col-md-4">
                    <label>FOB Value</label>
                    <input type="number" step="0.01" name="fob_value" class="form-control">
                </div>
            </div>

            {{-- Freight & Insurance --}}
            <div class="row mt-3">
                <div class="col-md-4">
                    <label>Freight</label>
                    <input type="number" step="0.01" name="freight" class="form-control">
                </div>

                <div class="col-md-4">
                    <label>Insurance</label>
                    <input type="number" step="0.01" name="insurance" class="form-control">
                </div>
            </div>

            {{-- Taxes & Incentives --}}
            <div class="row mt-3">
                <div class="col-md-3">
                    <label>IGST Value</label>
                    <input type="number" step="0.01" name="igst_value" class="form-control">
                </div>

                <div class="col-md-3">
                    <label>IGST %</label>
                    <input type="number" step="0.01" name="igst_rate" class="form-control">
                </div>

                <div class="col-md-3">
                    <label>DDB</label>
                    <input type="number" step="0.01" name="ddb" class="form-control">
                </div>

                <div class="col-md-3">
                    <label>RODTEP</label>
                    <input type="number" step="0.01" name="rodtep" class="form-control">
                </div>
            </div>

            <button class="btn btn-primary mt-4">
                Save Shipping Bill
            </button>
        </form>
    </div>
</div>
@endsection
