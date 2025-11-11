@extends('backend.layout.main')

@section('content')
    @push('css')
        <style>
            @media print {
                .hidden-print {
                    display: none !important;
                }
            }
        </style>
    @endpush

    @if (session('not_permitted'))
        <div class="alert alert-danger alert-dismissible text-center">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('not_permitted') }}
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger alert-dismissible text-center">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('error') }}
        </div>
    @endif

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


    @if (session('success'))
        <div class="alert alert-success alert-dismissible text-center">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('success') }}
        </div>
    @endif

    <section class="forms hidden-print">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h4>Create Forex Remittance</h4>
                </div>
                <div class="card-body">
                    <form action="{{ route('forex.remittance.store') }}" method="POST">
                        @csrf

                        <div class="row">
                            {{-- Party Type --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Party Type *</label>
                                    <select name="party_type" id="party_type" class="form-control" required>
                                        <option value="">Select Type</option>
                                        <option value="customer">Customer</option>
                                        <option value="supplier">Supplier</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Party Name --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Party Name *</label>
                                    <select name="party_id" id="party_id_option" class="party_id form-control selectpicker"
                                        data-live-search="true" required>
                                        {{-- Dynamic append via JS --}}
                                    </select>
                                </div>
                            </div>

                            {{-- Transaction Date --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Transaction Date *</label>
                                    <input type="date" name="transaction_date" class="form-control"
                                        value="{{ date('Y-m-d') }}" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            {{-- Base Currency --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Base Currency *</label>
                                    <select name="base_currency_id" id="base_currency_id" class="form-control" required>
                                        @foreach ($currency_list as $currency)
                                            <option value="{{ $currency->id }}" data-rate="{{ $currency->exchange_rate }}">
                                                {{ $currency->code }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Invoice Amount (Base) --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Invoice Base Amount *</label>
                                    <input type="number" step="0.01" name="invoice_amount" id="invoice_amount"
                                        class="form-control" required>
                                </div>
                            </div>

                            {{-- Closing Rate --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Closing Rate (Optional)</label>
                                    <input type="number" step="0.0001" name="closing_rate" id="closing_rate"
                                        class="form-control" placeholder="Optional for unrealised gain/loss">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            {{-- Local Currency --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Local Currency *</label>
                                    <select name="currency_id" id="currency_id" class="form-control" required>
                                        @foreach ($currency_list as $currency)
                                            <option value="{{ $currency->id }}" data-rate="{{ $currency->exchange_rate }}">
                                                {{ $currency->code }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Exchange Rate --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Exchange Rate</label>
                                    <input type="number" step="0.0001" name="exchange_rate" id="exchange_rate"
                                        class="form-control" placeholder="Enter manually or use backend default">
                                </div>
                            </div>

                            {{-- Converted Amount (Base → Local) --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Converted Amount (Local Currency)</label>
                                    <input type="number" step="0.01" id="local_amount" name="local_amount"
                                        class="form-control" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            {{-- Type (Receipt/Payment) --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Type *</label>
                                    <select name="type" id="type" class="form-control" required>
                                        <option value="receipt">Receipt</option>
                                        <option value="payment">Payment</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Reference / Voucher No --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Voucher / Reference No</label>
                                    <input type="text" name="voucher_no" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Average Exchange Rate (optional)</label>
                                    <input type="number" step="0.0001" name="avg_rate" id="avg_rate"
                                        class="form-control" placeholder="Leave empty to auto-calculate">
                                </div>
                            </div>

                            {{-- Status --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status" class="form-control">
                                        <option value="pending" selected>Pending</option>
                                        <option value="partial">Partial</option>
                                        <option value="realised">Realised</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {{-- Remarks --}}
                        <div class="form-group">
                            <label>Remarks</label>
                            <textarea name="remarks" class="form-control" rows="3"></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">Save Remittance</button>
                    </form>
                </div>

            </div>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        // Prepare data
        const customers = @json($lims_customer_list);
        const suppliers = @json($forex_suppliers);

        function populatePartyList(type) {
            console.log(type);
            let html = '<option value="">Select Party</option>';
            if (type == 'customer') {
                customers.forEach(c => html += `<option value="${c.id}">${c.name} (${c.currency.code})</option>`);
            } else if (type == 'supplier') {
                suppliers.forEach(s => html += `<option value="${s.id}">${s.name}</option>`);
            }
            console.log(html);
            $('#party_id_option').html(html).selectpicker('refresh');

        }

        $('#party_type').on('change', function() {
            populatePartyList($(this).val());
        });

        function calculateConverted() {
            const amount = parseFloat($('#invoice_amount').val()) || 0;
            const rate = parseFloat($('#exchange_rate').val()) ||
                parseFloat($('#currency_id option:selected').data('rate')) ||
                1;
            $('#local_amount').val((amount * rate).toFixed(2)); // updated ID
        }

        $('#invoice_amount, #exchange_rate').on('input', calculateConverted);
        $('#currency_id').on('change', calculateConverted);
    </script>
@endpush
