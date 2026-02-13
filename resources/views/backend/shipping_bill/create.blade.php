@extends('backend.layout.main')

@section('content')

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible text-center">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

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
                                <option value="{{ $inv->id }}"
                                    {{ old('transaction_id') == $inv->id ? 'selected' : '' }}>
                                    {{ $inv->voucher_no }} |
                                    {{ $inv->transaction_date }} |
                                    USD {{ $inv->base_amount }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label>Shipping Bill No *</label>
                        <input type="text" name="shipping_bill_no" class="form-control"
                            value="{{ old('shipping_bill_no') }}" required>
                    </div>

                    <div class="col-md-4">
                        <label>Shipping Bill Date *</label>
                        <input type="date" name="shipping_bill_date" class="form-control"
                            value="{{ old('shipping_bill_date') }}" required>
                    </div>
                </div>

                {{-- Port & FOB --}}
                <div class="row mt-3">
                    <div class="col-md-4">
                        <label>Port *</label>
                        <input type="text" name="port" class="form-control" value="{{ old('port') }}" required>
                    </div>

                    <div class="col-md-4">
                        <label>FOB Value</label>
                        <input type="number" step="0.01" name="fob_value" class="form-control"
                            value="{{ old('fob_value') }}">
                    </div>
                </div>

                {{-- Freight & Insurance --}}
                <div class="row mt-3">
                    <div class="col-md-4">
                        <label>Freight</label>
                        <input type="number" step="0.01" name="freight" class="form-control"
                            value="{{ old('freight') }}">
                    </div>

                    <div class="col-md-4">
                        <label>Insurance</label>
                        <input type="number" step="0.01" name="insurance" class="form-control"
                            value="{{ old('insurance') }}">
                    </div>
                </div>

                {{-- Taxes & Incentives --}}
                <div class="row mt-3">

                    <div class="col-md-3">
                        <label>Taxable Amount</label>
                        <input type="number" step="0.01" name="taxable_amount" class="form-control"
                            value="{{ old('taxable_amount') }}">
                    </div>

                    <div class="col-md-3">
                        <label>IGST Value</label>
                        <input type="number" step="0.01" name="igst_value" class="form-control"
                            value="{{ old('igst_value') }}">
                    </div>

                    <div class="col-md-3">
                        <label>Net Amount</label>
                        <input type="number" step="0.01" name="net_amount" class="form-control"
                            value="{{ old('net_amount') }}" readonly>
                    </div>

                    <div class="col-md-3">
                        <label>DDB</label>
                        <input type="number" step="0.01" name="ddb" class="form-control"
                            value="{{ old('ddb') }}">
                    </div>

                    <div class="col-md-3 mt-3">
                        <label>DDB Date</label>
                        <input type="date" name="ddb_date" class="form-control" value="{{ old('ddb_date') }}">
                    </div>


                    <div class="col-md-3 mt-3">
                        <label>DDB Status</label>
                        <select name="ddb_status" class="form-control" required>
                            <option value="pending" {{ old('ddb_status') == 'pending' ? 'selected' : '' }}>
                                Pending
                            </option>
                            <option value="received" {{ old('ddb_status') == 'received' ? 'selected' : '' }}>
                                Received
                            </option>
                        </select>
                    </div>

                    <div class="col-md-3 mt-3">
                        <label>RODTEP</label>
                        <input type="number" step="0.01" name="rodtep" class="form-control"
                            value="{{ old('rodtep') }}">
                    </div>

                    <div class="col-md-3 mt-3">
                        <label>RODTEP Date</label>
                        <input type="date" name="rodtep_date" class="form-control" value="{{ old('rodtep_date') }}">
                    </div>
                    <div class="col-md-3 mt-3">
                        <label>RODTEP Status</label>
                        <select name="rodtep_status" class="form-control" required>
                            <option value="pending" selected {{ old('rodtep_status') == 'pending' ? 'selected' : '' }}>
                                Pending
                            </option>
                            <option value="received" {{ old('rodtep_status') == 'received' ? 'selected' : '' }}>
                                Received
                            </option>
                        </select>
                    </div>

                </div>

                <button class="btn btn-primary mt-4">
                    Save Shipping Bill
                </button>

            </form>
        </div>
    </div>

@endsection


@push('scripts')
    <script>
        // Auto calculate Net Amount
        $('input[name="taxable_amount"], input[name="igst_value"]').on('input', function() {

            let taxable = parseFloat($('input[name="taxable_amount"]').val()) || 0;
            let igst = parseFloat($('input[name="igst_value"]').val()) || 0;

            $('input[name="net_amount"]').val((taxable + igst).toFixed(2));
        });
    </script>
@endpush
