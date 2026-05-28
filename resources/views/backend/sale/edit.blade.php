@extends('backend.layout.main')

@section('content')

    @push('css')
        <style>
            @media print {
                .hidden-print {
                    display: none !important;
                }
            }

            .bootstrap-select .dropdown-toggle:focus {
                outline: 2px solid #4a90e2;
                outline-offset: 2px;
            }

            .settlement-amount {
                min-width: 140px;
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

                    <form action="{{ route('forex.remittance.update', $transaction->id) }}" method="POST"
                        enctype="multipart/form-data">

                        @csrf
                        @method('PUT')

                        {{-- EXISTING MATCHES --}}
                        <script>
                            window.existingManualMatches =
                                @json($existingMatches ?? []);
                        </script>

                        <div class="row">

                            {{-- PARTY TYPE --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Party Type (optional)
                                    </label>

                                    <select name="party_type" id="party_type" class="form-control selectpicker"
                                        data-live-search="true">

                                        <option value="">
                                            -- Any --
                                        </option>

                                        <option value="customer"
                                            {{ $transaction->party_type == 'customer' ? 'selected' : '' }}>
                                            Customer
                                        </option>

                                        <option value="supplier"
                                            {{ $transaction->party_type == 'supplier' ? 'selected' : '' }}>
                                            Supplier
                                        </option>

                                    </select>

                                </div>

                            </div>

                            {{-- PARTY --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Party Name *
                                    </label>

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

                            {{-- DATE --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Transaction Date *
                                    </label>

                                    <input type="date" name="transaction_date" class="form-control"
                                        value="{{ \Carbon\Carbon::parse($transaction->transaction_date)->format('Y-m-d') }}"
                                        required>

                                </div>

                            </div>

                        </div>

                        {{-- ROW 2 --}}
                        <div class="row">

                            {{-- BASE CURRENCY --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Base Currency *
                                    </label>

                                    <select name="base_currency_id" id="base_currency_id" class="form-control selectpicker"
                                        data-live-search="true" required>

                                        @foreach ($currency_list as $c)
                                            <option value="{{ $c->id }}" data-rate="{{ $c->exchange_rate }}"
                                                {{ $transaction->base_currency_id == $c->id ? 'selected' : '' }}>

                                                {{ $c->code }}

                                            </option>
                                        @endforeach

                                    </select>

                                </div>

                            </div>

                            {{-- BASE AMOUNT --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Base Amount *
                                    </label>

                                    <input type="number" step="0.0001" name="base_amount" id="base_amount"
                                        value="{{ $transaction->base_amount }}" class="form-control" required>

                                </div>

                            </div>

                            {{-- CLOSING RATE --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Closing Rate
                                    </label>

                                    <input type="number" step="0.0001" name="closing_rate" id="closing_rate"
                                        value="{{ $transaction->closing_rate }}" class="form-control">

                                </div>

                            </div>

                        </div>

                        {{-- ROW 3 --}}
                        <div class="row">

                            {{-- LOCAL CURRENCY --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Local Currency *
                                    </label>

                                    <select name="local_currency_id" id="local_currency_id"
                                        class="form-control selectpicker" data-live-search="true" required>

                                        @foreach ($currency_list as $c)
                                            <option value="{{ $c->id }}" data-rate="{{ $c->exchange_rate }}"
                                                {{ $transaction->local_currency_id == $c->id ? 'selected' : '' }}>

                                                {{ $c->code }}

                                            </option>
                                        @endforeach

                                    </select>

                                </div>

                            </div>

                            {{-- EXCHANGE RATE --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Exchange Rate
                                    </label>

                                    <input type="number" step="0.0001" name="exchange_rate" id="exchange_rate"
                                        value="{{ $transaction->exchange_rate }}" class="form-control">

                                </div>

                            </div>

                            {{-- LOCAL AMOUNT --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Converted Amount
                                    </label>

                                    <input type="number" step="0.01" id="local_amount"
                                        value="{{ $transaction->local_amount }}" name="local_amount" class="form-control"
                                        readonly>

                                </div>

                            </div>

                        </div>

                        {{-- ROW 4 --}}
                        <div class="row">

                            {{-- VOUCHER TYPE --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Voucher Type *
                                    </label>

                                    <select name="voucher_type" id="voucher_type" class="form-control selectpicker"
                                        data-live-search="true" required>

                                        <option value="receipt"
                                            {{ $transaction->voucher_type == 'receipt' ? 'selected' : '' }}>
                                            Receipt
                                        </option>

                                        <option value="payment"
                                            {{ $transaction->voucher_type == 'payment' ? 'selected' : '' }}>
                                            Payment
                                        </option>

                                        <option value="sale"
                                            {{ $transaction->voucher_type == 'sale' ? 'selected' : '' }}>
                                            Sale
                                        </option>

                                        <option value="purchase"
                                            {{ $transaction->voucher_type == 'purchase' ? 'selected' : '' }}>
                                            Purchase
                                        </option>

                                    </select>

                                </div>

                            </div>

                            {{-- VOUCHER NO --}}
                            <div class="col-md-4">

                                <div class="form-group">

                                    <label>
                                        Voucher / Reference No *
                                    </label>

                                    <input type="text" name="voucher_no" value="{{ $transaction->voucher_no }}"
                                        class="form-control" required>

                                </div>

                            </div>

                        </div>

                        {{-- MANUAL SETTLEMENT --}}
                        <div id="manual-settlement-box"></div>

                        {{-- REMARKS --}}
                        <div class="form-group mt-3">

                            <label>
                                Remarks
                            </label>

                            <textarea name="remarks" class="form-control" rows="3">{{ $transaction->remarks }}</textarea>

                        </div>

                        {{-- ATTACHMENT --}}
                        <div class="form-group">

                            <label>
                                Attachment
                            </label>

                            <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">

                            @if ($transaction->attachment)
                                <div class="mt-2">

                                    <a href="{{ url($transaction->attachment) }}" target="_blank"
                                        class="btn btn-sm btn-outline-info">
                                        View Current Attachment
                                    </a>

                                </div>
                            @endif

                        </div>

                        <button type="submit" class="btn btn-primary">
                            Update Remittance
                        </button>

                    </form>

                </div>

            </div>

        </div>

    </section>

@endsection

@push('scripts')
    <script>
        /*
        |--------------------------------------------------------------------------
        | INIT
        |--------------------------------------------------------------------------
        */

        $(document).ready(function() {

            $('.selectpicker').selectpicker();

            calculateConverted();

            loadManualSettlements();
        });


        /*
        |--------------------------------------------------------------------------
        | AUTO LOCAL AMOUNT
        |--------------------------------------------------------------------------
        */

        function calculateConverted() {
            const amount =
                parseFloat($('#base_amount').val()) || 0;

            const rate =
                parseFloat($('#exchange_rate').val()) ||
                parseFloat(
                    $('#local_currency_id option:selected')
                    .data('rate')
                ) ||
                1;

            $('#local_amount').val(
                (amount * rate).toFixed(2)
            );
        }

        $(document).on(
            'input',
            '#base_amount, #exchange_rate',
            calculateConverted
        );

        $(document).on(
            'change',
            '#local_currency_id',
            calculateConverted
        );


        /*
        |--------------------------------------------------------------------------
        | AUTO PARTY TYPE
        |--------------------------------------------------------------------------
        */

        $(document).on(
            'change',
            '#party_id_option',
            function() {

                const type =
                    $(this)
                    .find('option:selected')
                    .data('type') || '';

                $('#party_type')
                    .val(type)
                    .selectpicker('refresh');
            }
        );


        /*
        |--------------------------------------------------------------------------
        | LOAD MANUAL SETTLEMENTS
        |--------------------------------------------------------------------------
        */

        function loadManualSettlements() {
            const partyId =
                $('#party_id_option').val();

            const voucherType =
                $('#voucher_type').val();

            if (!partyId || !voucherType) {

                $('#manual-settlement-box')
                    .html('');

                return;
            }

            $.ajax({

                url: "{{ route('forex.remittance.open-vouchers') }}",

                type: 'GET',

                data: {
                    party_id: partyId,
                    voucher_type: voucherType
                },

                success: function(response) {

                    if (!response.length) {

                        $('#manual-settlement-box')
                            .html('');

                        return;
                    }

                    let existing =
                        window.existingManualMatches || [];

                    let html = `
                <div class="card mt-3">

                    <div class="card-header bg-info text-white">

                        <strong>
                            Manual Settlement Allocation
                        </strong>

                    </div>

                    <div class="card-body p-0">

                        <div class="table-responsive">

                            <table class="table table-bordered table-sm mb-0">

                                <thead class="thead-light">

                                    <tr>

                                        <th width="60">
                                            Select
                                        </th>

                                        <th>
                                            Voucher
                                        </th>

                                        <th>
                                            Date
                                        </th>

                                        <th>
                                            Type
                                        </th>

                                        <th class="text-right">
                                            Amount
                                        </th>

                                        <th class="text-right">
                                            Open
                                        </th>

                                        <th width="180">
                                            Settle Amount
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>
                `;

                    response.forEach((item, index) => {

                        let oldMatch =
                            existing.find(
                                x =>
                                parseInt(x.transaction_id) ===
                                parseInt(item.id)
                            );

                        let checked =
                            oldMatch ? 'checked' : '';

                        let amount =
                            oldMatch ?
                            parseFloat(oldMatch.amount)
                            .toFixed(4) :
                            '';

                        html += `
                    <tr>

                        <td class="text-center">

                            <input
                                type="checkbox"
                                class="manual-match-checkbox"
                                data-index="${index}"
                                ${checked}
                            >

                        </td>

                        <td>

                            <strong>
                                ${item.voucher_no}
                            </strong>

                            <input
                                type="hidden"
                                name="manual_matches[${index}][transaction_id]"
                                value="${item.id}"
                            >

                        </td>

                        <td>
                            ${item.transaction_date}
                        </td>

                        <td>
                            ${item.voucher_type}
                        </td>

                        <td class="text-right">
                            ${parseFloat(item.base_amount).toFixed(4)}
                        </td>

                        <td class="text-right text-primary font-weight-bold">
                            ${parseFloat(item.remaining_amount).toFixed(4)}
                        </td>

                        <td>

                            <input
                                type="number"
                                step="0.0001"
                                min="0"
                                class="form-control form-control-sm settlement-amount"
                                name="manual_matches[${index}][amount]"
                                value="${amount}"
                                ${checked ? '' : 'disabled'}
                            >

                        </td>

                    </tr>
                    `;
                    });

                    html += `
                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>
                `;

                    $('#manual-settlement-box')
                        .html(html);
                }
            });
        }


        /*
        |--------------------------------------------------------------------------
        | RELOAD ON CHANGE
        |--------------------------------------------------------------------------
        */

        $(document).on(
            'change',
            '#party_id_option, #voucher_type',
            function() {

                loadManualSettlements();
            }
        );


        /*
        |--------------------------------------------------------------------------
        | ENABLE DISABLE AMOUNT
        |--------------------------------------------------------------------------
        */

        $(document).on(
            'change',
            '.manual-match-checkbox',
            function() {

                const row =
                    $(this).closest('tr');

                const input =
                    row.find('.settlement-amount');

                if ($(this).is(':checked')) {

                    input
                        .prop('disabled', false)
                        .focus();

                } else {

                    input
                        .prop('disabled', true)
                        .val('');
                }
            }
        );


        /*
        |--------------------------------------------------------------------------
        | LIMIT TOTAL
        |--------------------------------------------------------------------------
        */

        $(document).on(
            'input',
            '.settlement-amount',
            function() {

                const totalBase =
                    parseFloat(
                        $('#base_amount').val()
                    ) || 0;

                let used = 0;

                $('.settlement-amount').each(function() {

                    if (!$(this).prop('disabled')) {

                        used +=
                            parseFloat($(this).val()) || 0;
                    }
                });

                if (used > totalBase) {

                    alert(
                        'Settlement total cannot exceed Base Amount'
                    );

                    $(this).val('');
                }
            }
        );
    </script>
@endpush
