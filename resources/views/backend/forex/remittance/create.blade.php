@extends('backend.layout.main')

@section('content')
@push('css')
<style>
    @media print {
        .hidden-print { display: none !important; }
    }
</style>
@endpush

@if(session()->has('not_permitted'))
<div class="alert alert-danger alert-dismissible text-center">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    {{ session()->get('not_permitted') }}
</div>
@endif

@if(session()->has('success'))
<div class="alert alert-success alert-dismissible text-center">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    {{ session()->get('success') }}
</div>
@endif

@if(session()->has('error'))
<div class="alert alert-danger alert-dismissible text-center">
    <button type="button" class="close" data-dismiss="alert">&times;</button>
    {{ session()->get('error') }}
</div>
@endif

<section id="pos-layout" class="forms hidden-print">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h4>Create Forex Remittance</h4>
                    </div>
                    <div class="card-body">
                        <p class="italic"><small>The fields marked with * are required input fields.</small></p>
                        <form action="{{ route('forex.remittance.store') }}" method="POST">
                            @csrf
                            <div class="row">

                                {{-- Party Type --}}
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Party Type *</label>
                                        <select name="party_type" id="party_type" class="form-control" required>
                                            <option value="">Select Type</option>
                                            <option value="customer">Customer</option>
                                            <option value="supplier">Supplier</option>
                                        </select>
                                    </div>
                                </div>

                                {{-- Party --}}
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Party *</label>
                                        <select name="party_id" id="party_id" class="form-control" required>
                                            <option value="">Select Party</option>
                                        </select>
                                    </div>
                                </div>

                                {{-- Currency --}}
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Currency *</label>
                                        <select name="currency_id" id="currency_id" class="form-control selectpicker">
                                            @foreach($currency_list as $currency)
                                                <option value="{{ $currency->id }}" data-rate="{{ $currency->exchange_rate }}">
                                                    {{ $currency->code }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                {{-- Transaction Date --}}
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Transaction Date *</label>
                                        <input type="date" name="transaction_date" class="form-control" required>
                                    </div>
                                </div>

                                {{-- USD Amount --}}
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>USD Amount *</label>
                                        <input type="number" step="0.01" name="usd_amount" class="form-control" required>
                                    </div>
                                </div>

                                {{-- Exchange Rate --}}
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Exchange Rate *</label>
                                        <input type="number" step="0.0001" name="exch_rate" class="form-control" required>
                                    </div>
                                </div>

                                {{-- Type --}}
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Type *</label>
                                        <select name="type" class="form-control" required>
                                            <option value="receipt">Receipt</option>
                                            <option value="payment">Payment</option>
                                        </select>
                                    </div>
                                </div>

                                {{-- Reference No --}}
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Reference No</label>
                                        <input type="text" name="reference_no" class="form-control">
                                    </div>
                                </div>

                                {{-- Remarks --}}
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label>Remarks</label>
                                        <textarea name="remarks" class="form-control"></textarea>
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <button type="submit" class="btn btn-primary">Save Remittance</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script>
    const customers = @json($lims_customer_list);
    const suppliers = @json($forex_suppliers);

    $('#party_type').on('change', function() {
        let type = $(this).val();
        let options = '<option value="">Select Party</option>';
        if(type === 'customer') {
            customers.forEach(c => {
                options += `<option value="${c.id}">${c.name} (${c.currency.code})</option>`;
            });
        } else if(type === 'supplier') {
            suppliers.forEach(s => {
                options += `<option value="${s.id}">${s.name}</option>`;
            });
        }
        $('#party_id').html(options);
    });
</script>
@endpush
