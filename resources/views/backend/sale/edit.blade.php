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
                    <h4>Edit Forex Remittance</h4>
                </div>

                <div class="card-body">

                    {{-- Update route --}}
                    <form action="{{ route('forex.remittance.update', $transaction->id) }}" method="POST"
                        enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="row">

                            {{-- Party Type --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Party Type (optional)</label>
                                    <select name="party_type" id="party_type" class="form-control">
                                        <option value="">-- Any --</option>
                                        <option value="customer"
                                            {{ $transaction->party_type == 'customer' ? 'selected' : '' }}>
                                            Customer</option>
                                        <option value="supplier"
                                            {{ $transaction->party_type == 'supplier' ? 'selected' : '' }}>
                                            Supplier</option>
                                    </select>
                                </div>
                            </div>

                            {{-- Party --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Party Name *</label>
                                    <select name="party_id" id="party_id_option" class="form-control selectpicker"
                                        data-live-search="true" required>

                                        @foreach ($party as $p)
                                            <option value="{{ $p->id }}"
                                                data-type="{{ $p->party_type ?? 'customer' }}"
                                                {{ $transaction->party_id == $p->id ? 'selected' : '' }}>
                                                {{ $p->name }}
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
                                        value="{{ $transaction->transaction_date }}" required>
                                </div>
                            </div>

                        </div>


                        {{-- Row 2 --}}
                        <div class="row">
                            {{-- Base Currency --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Base Currency *</label>
                                    <select name="base_currency_id" id="base_currency_id" class="form-control" required>
                                        @foreach ($currency_list as $c)
                                            <option value="{{ $c->id }}" data-rate="{{ $c->exchange_rate }}"
                                                {{ $transaction->base_currency_id == $c->id ? 'selected' : '' }}>
                                                {{ $c->code }}
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
                                        value="{{ $transaction->base_amount }}" class="form-control" required>
                                </div>
                            </div>

                            {{-- Closing Rate --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Closing Rate (Optional)</label>
                                    <input type="number" step="0.0001" name="closing_rate" id="closing_rate"
                                        value="{{ $transaction->closing_rate }}" class="form-control">
                                </div>
                            </div>
                        </div>

                        {{-- Row 3 --}}
                        <div class="row">

                            {{-- Local Currency --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Local Currency *</label>
                                    <select name="local_currency_id" id="local_currency_id" class="form-control" required>
                                        @foreach ($currency_list as $c)
                                            <option value="{{ $c->id }}" data-rate="{{ $c->exchange_rate }}"
                                                {{ $transaction->local_currency_id == $c->id ? 'selected' : '' }}>
                                                {{ $c->code }}
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
                                        value="{{ $transaction->exchange_rate }}" class="form-control">
                                </div>
                            </div>

                            {{-- Local Amount --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Converted Amount</label>
                                    <input type="number" step="0.01" id="local_amount"
                                        value="{{ $transaction->local_amount }}" name="local_amount" class="form-control"
                                        readonly>
                                </div>
                            </div>

                        </div>

                        {{-- Row 4 --}}
                        <div class="row">

                            {{-- Voucher type --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Voucher Type *</label>
                                    <select name="voucher_type" id="voucher_type" class="form-control" required>
                                        <option value="receipt"
                                            {{ $transaction->voucher_type == 'receipt' ? 'selected' : '' }}>Receipt
                                        </option>
                                        <option value="payment"
                                            {{ $transaction->voucher_type == 'payment' ? 'selected' : '' }}>Payment
                                        </option>
                                        <option value="sale"
                                            {{ $transaction->voucher_type == 'sale' ? 'selected' : '' }}>
                                            Sale</option>
                                        <option value="purchase"
                                            {{ $transaction->voucher_type == 'purchase' ? 'selected' : '' }}>Purchase
                                        </option>
                                    </select>
                                </div>
                            </div>

                            {{-- Voucher No --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Voucher / Reference No *</label>
                                    <input type="text" name="voucher_no" value="{{ $transaction->voucher_no }}"
                                        class="form-control" required>
                                </div>
                            </div>

                        </div>

                        {{-- Remarks --}}
                        <div class="form-group">
                            <label>Remarks</label>
                            <textarea name="remarks" class="form-control" rows="3">{{ $transaction->remarks }}</textarea>
                        </div>


                        {{-- Attachment --}}
                        <div class="form-group">
                            <label>Attachment (Invoice / Proof)</label>
                            <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">

                            @if ($transaction->attachment)
                                <div class="mt-2">
                                    <a href="{{ url($transaction->attachment) }}" target="_blank"
                                        class="btn btn-sm btn-outline-info">
                                        View Current Attachment
                                    </a>
                                </div>
                            @endif

                            <small class="text-muted">PDF / JPG / PNG only (Max 5MB)</small>
                        </div>

                        <button type="submit" class="btn btn-primary">Update Remittance</button>
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

        // Auto-fill party type on party change
        $('#party_id_option').on('change', function() {
            const type = $(this).find('option:selected').data('type') || '';
            $('#party_type').val(type);
        });
    </script>
@endpush
