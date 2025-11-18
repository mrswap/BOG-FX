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
                            {{-- Party Type (optional) --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Party Type (optional)</label>
                                    <select name="party_type" id="party_type" class="form-control">
                                        <option value="">-- Any --</option>
                                        <option value="customer">Customer</option>
                                        <option value="supplier">Supplier</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Party Name (merged customers + suppliers) --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Party Name *</label>
                                    <select name="party_id" id="party_id_option" class="form-control selectpicker"
                                        data-live-search="true" required>
                                        <option value="">Select Party</option>
                                        @foreach ($party as $c)
                                            <option value="{{ $c->id }}" data-type="customer">
                                                {{ $c->name }} 
                                            </option>
                                        @endforeach

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

                            {{-- Base Amount --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Base Amount *</label>
                                    <input type="number" step="0.01" name="base_amount" id="base_amount"
                                        class="form-control" required>
                                </div>
                            </div>

                            {{-- Closing Rate --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Closing Rate (Optional)</label>
                                    <input type="number" step="0.0001" name="closing_rate" id="closing_rate"
                                        class="form-control" placeholder="Optional for unrealised forex gain/loss">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            {{-- Local Currency --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Local Currency *</label>
                                    <select name="local_currency_id" id="local_currency_id" class="form-control" required>
                                        @foreach ($currency_list as $currency)
                                            <option value="{{ $currency->id }}"
                                                data-rate="{{ $currency->exchange_rate }}">
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
                                        class="form-control" placeholder="Enter manually or auto">
                                </div>
                            </div>

                            {{-- Converted Amount --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Converted Amount (Local Currency)</label>
                                    <input type="number" step="0.01" id="local_amount" name="local_amount"
                                        class="form-control" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            {{-- Voucher Type (renamed and expanded) --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Voucher Type *</label>
                                    <select name="voucher_type" id="voucher_type" class="form-control" required>
                                        <option value="receipt">Receipt</option>
                                        <option value="payment">Payment</option>
                                        <option value="sale">Sale</option>
                                        <option value="purchase">Purchase</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Voucher No --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Voucher / Reference No *</label>
                                    <input type="text" name="voucher_no" class="form-control" required>
                                </div>
                            </div>

                            {{-- Average Rate --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Average Exchange Rate (optional)</label>
                                    <input type="number" step="0.0001" name="avg_rate" id="avg_rate"
                                        class="form-control" placeholder="Leave empty to auto-calc">
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
        // Auto-calc Converted Amount
        function calculateConverted() {
            const amount = parseFloat($('#base_amount').val()) || 0;
            const rate = parseFloat($('#exchange_rate').val()) ||
                parseFloat($('#local_currency_id option:selected').data('rate')) ||
                1;
            $('#local_amount').val((amount * rate).toFixed(2));
        }

        $('#base_amount, #exchange_rate').on('input', calculateConverted);
        $('#local_currency_id').on('change', calculateConverted);

        // Auto-fill party_type when a party is selected
        $('#party_id_option').on('change', function() {
            const partyType = $(this).find('option:selected').data('type') || '';
            $('#party_type').val(partyType);
        });
    </script>
@endpush
