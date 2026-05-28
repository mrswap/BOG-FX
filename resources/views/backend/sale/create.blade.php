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
                    <form action="{{ route('forex.remittance.store') }}" method="POST" enctype="multipart/form-data">
                        @csrf

                        <div class="row">
                            {{-- Party Type (optional) --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Party Type (optional)</label>
                                    <select name="party_type" id="party_type" class="form-control selectpicker"
                                        data-live-search="true">

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
                                        max="{{ date('Y-m-d') }}" value="{{ date('Y-m-d') }}" required>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            {{-- Base Currency --}}
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label>Base Currency *</label>
                                    <select name="base_currency_id" id="base_currency_id" class="form-control selectpicker"
                                        data-live-search="true" required>

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
                                    <select name="local_currency_id" id="local_currency_id"
                                        class="form-control selectpicker" data-live-search="true" required>

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
                                    <select name="voucher_type" id="voucher_type" class="form-control selectpicker"
                                        data-live-search="true" required>

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




                            <div id="manual-settlement-box" style="display:none;">
                            </div>



                        </div>



                        {{-- Remarks --}}
                        <div class="form-group">
                            <label>Remarks</label>
                            <textarea name="remarks" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Attachment (Invoice / Proof)</label>
                            <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="text-muted">Allowed: PDF, JPG, PNG (Max 5MB)</small>
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
        /*
                            |--------------------------------------------------------------------------
                            | AUTO CALCULATE LOCAL AMOUNT
                            |--------------------------------------------------------------------------
                            */

        function calculateConverted() {

            const amount =
                parseFloat($('#base_amount').val()) || 0;

            const rate =
                parseFloat($('#exchange_rate').val()) ||
                parseFloat(
                    $('#local_currency_id option:selected').data('rate')
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
    </script>

    <script>
        /*
                            |--------------------------------------------------------------------------
                            | AUTO SET PARTY TYPE
                            |--------------------------------------------------------------------------
                            */

        $(document).on(
            'change',
            '#party_id_option',
            function() {

                const partyType =
                    $(this)
                    .find('option:selected')
                    .data('type') || '';

                $('#party_type')
                    .val(partyType)
                    .selectpicker('refresh');
            }
        );
    </script>

    <script>
        /*
                            |--------------------------------------------------------------------------
                            | DATE VALIDATION
                            |--------------------------------------------------------------------------
                            */

        $(document).on(
            'blur change',
            'input[type="date"]',
            function() {

                let val = $(this).val();

                if (!val) return;

                let parts = val.split('-');

                if (parts.length !== 3) return;

                let year = parts[0];
                let month = parts[1];
                let day = parts[2];

                if (year.length <= 2) {

                    year = (
                        2000 + parseInt(year, 10)
                    ).toString();
                }

                let finalYear = parseInt(year, 10);

                if (
                    finalYear < 2000 ||
                    finalYear > 2100
                ) {

                    alert(
                        'Year must be between 2000 and 2100'
                    );

                    $(this).val('');

                    return;
                }

                $(this).val(
                    `${year}-${month}-${day}`
                );
            }
        );
    </script>

    <script>
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
                    .hide()
                    .html('');

                return;
            }

            $.ajax({

                url: "{{ route('forex.remittance.open-vouchers') }}",

                type: "GET",

                data: {
                    party_id: partyId,
                    voucher_type: voucherType
                },

                success: function(response) {

                    if (!response.length) {

                        $('#manual-settlement-box')
                            .html(`
                                <div class="alert alert-warning mt-3">
                                    No opposite vouchers found.
                                </div>
                            `)
                            .show();

                        return;
                    }

                    let html = `
                        <div class="card mt-3">

                            <div class="card-header bg-info text-white">

                                <strong>
                                    Manual Settlement Allocation
                                </strong>

                                <small class="float-right">
                                    Manual selection gets priority over FIFO
                                </small>

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

                                                <th class="text-right">
                                                    Original
                                                </th>

                                                <th class="text-right">
                                                    Settled
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

                        html += `
                            <tr>

                                <td class="text-center">

                                    <input
                                        type="checkbox"
                                        class="manual-match-checkbox"
                                        data-index="${index}"
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

                                <td class="text-right">
                                    ${parseFloat(item.base_amount).toFixed(4)}
                                </td>

                                <td class="text-right">
                                    ${parseFloat(item.matched_amount).toFixed(4)}
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
                                        placeholder="0.0000"
                                        disabled
                                        autocomplete="off"
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
                        .html(html)
                        .show();
                },

                error: function() {

                    $('#manual-settlement-box')
                        .html(`
                            <div class="alert alert-danger mt-3">
                                Failed to load settlement vouchers.
                            </div>
                        `)
                        .show();
                }
            });
        }
    </script>

    <script>
        /*
                            |--------------------------------------------------------------------------
                            | LOAD ON PARTY / VOUCHER CHANGE
                            |--------------------------------------------------------------------------
                            */

        $(document).on(
            'change',
            '#party_id_option, #voucher_type',
            function() {

                loadManualSettlements();
            }
        );
    </script>

    <script>
        /*
                            |--------------------------------------------------------------------------
                            | ENABLE / DISABLE SETTLEMENT INPUT
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
    </script>

    <script>
        /*
                            |--------------------------------------------------------------------------
                            | PREVENT TOTAL EXCEEDING BASE AMOUNT
                            |--------------------------------------------------------------------------
                            */

        $(document).on(
            'input',
            '.settlement-amount',
            function() {

                const totalBase =
                    parseFloat($('#base_amount').val()) || 0;

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

    <script>
        /*
                            |--------------------------------------------------------------------------
                            | BOOTSTRAP SELECT FIX
                            |--------------------------------------------------------------------------
                            */

        $(document).on(
            'keydown',
            '.bootstrap-select .dropdown-toggle',
            function(e) {

                if (
                    e.key.length === 1 &&
                    !e.ctrlKey &&
                    !e.metaKey &&
                    !e.altKey
                ) {

                    e.stopPropagation();
                }
            }
        );
    </script>

    <script>
        /*
                            |--------------------------------------------------------------------------
                            | INITIAL LOAD
                            |--------------------------------------------------------------------------
                            */

        $(document).ready(function() {

            $('.selectpicker').selectpicker();

            calculateConverted();

            loadManualSettlements();
        });
    </script>
@endpush
