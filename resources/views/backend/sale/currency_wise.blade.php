@extends('backend.layout.main')

@section('content')
    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible text-center">
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
            {!! session()->get('message') !!}
        </div>
    @endif
    @if (session()->has('not_permitted'))
        <div class="alert alert-danger alert-dismissible text-center">
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
            {{ session()->get('not_permitted') }}
        </div>
    @endif

    <section>
        <div class="container-fluid">

            <div class="card mt-3">
                <h3 class="text-center mt-3">Filter Forex Remittances</h3>
                <div class="card-body">
                    {!! Form::open(['route' => 'sales.index', 'method' => 'get']) !!}
                    <div class="row mt-2">
                        <div class="col-md-3">
                            <label><strong>From Date</strong></label>
                            <input type="text" name="starting_date" autocomplete="off" class="form-control datepicker"
                                required>
                        </div>

                        <div class="col-md-3">
                            <label><strong>To Date</strong></label>
                            <input type="text" name="ending_date" autocomplete="off" class="form-control datepicker"
                                required>
                        </div>

                        <div class="col-md-2">
                            <div class="form-group">
                                <label><strong>Base Currency</strong></label>
                                <select name="base_currency_id" class="form-control">
                                    <option value="0">All</option>
                                    @foreach ($currencies as $currency)
                                        <option value="{{ $currency->id }}" data-rate="{{ $currency->exchange_rate }}">
                                            {{ $currency->code }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <div class="form-group">
                                <label><strong>Local Currency</strong></label>
                                <select name="local_currency_id" class="form-control">
                                    <option value="0">All</option>
                                    @foreach ($currencies as $currency)
                                        <option value="{{ $currency->id }}" data-rate="{{ $currency->exchange_rate }}">
                                            {{ $currency->code }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>



                        <div class="col-md-2 mt-3">
                            <button class="btn btn-primary" type="submit" id="filter-btn">Submit</button>
                        </div>
                    </div>
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
        <div class="table-responsive mt-3">
            <table id="forex-table" class="table table-bordered" style="width:100%">
                <thead class="thead-dark">
                    <tr>
                        <th>Sn.</th>
                        <th>Date</th>
                        <th>Particulars</th>
                        <th>Vch Type</th>
                        <th>Vch No.</th>
                        <th>Exch Rate</th>
                        <th>Base Currency<br><small>(Debit)</small></th>
                        <th>Base Currency<br><small>(Credit)</small></th>
                        <th>Local Currency<br><small>(Debit)</small></th>
                        <th>Local Currency<br><small>(Credit)</small></th>
                        <th>Avg Rate</th>
                        <th>Closing Rate</th>

                        <th>Diff</th>
                        <th>Realised Gain/Loss</th>
                        <th>Unrealised Gain/Loss</th>
                        <th>Remarks</th>
                        <th class="text-center">Action</th>

                    </tr>
                </thead>
                <tfoot>
                    <tr>
                        <th></th> <!-- sn -->
                        <th></th> <!-- date -->
                        <th></th> <!-- particulars -->
                        <th></th> <!-- vch type -->
                        <th></th> <!-- vch no -->
                        <th></th> <!-- exch rate -->

                        <th id="total-base-debit"></th>
                        <th id="total-base-credit"></th>
                        <th id="total-local-debit"></th>
                        <th id="total-local-credit"></th>

                        <th></th> <!-- avg rate -->
                        <th></th> <!-- closing rate -->
                        <th></th> <!-- diff -->

                        <th id="total-realised"></th>
                        <th id="total-unrealised"></th>
                        <th id="final-gain-loss"></th>

                    </tr>
                    <tr>
                        <th colspan="6" class="text-right font-weight-bold">Net Balance</th>
                        <th colspan="10" id="party-net-balance" class="text-left font-weight-bold"></th>
                    </tr>

                </tfoot>


            </table>
        </div>

    </section>

    <!-- Details Modal -->
    <div id="forex-details" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h4>Forex Remittance Details</h4>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body" id="forex-content"></div>
                <div class="modal-footer" id="forex-footer"></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $('input[name="starting_date"], input[name="ending_date"]').datepicker({
            format: "yyyy-mm-dd",
            autoclose: true,
            todayHighlight: true
        });

        // Optional: ensure ToDate >= FromDate
        $('input[name="starting_date"]').on('changeDate', function() {
            $('input[name="ending_date"]').datepicker('setStartDate', $(this).val());
        });

        $('input[name="ending_date"]').on('changeDate', function() {
            $('input[name="starting_date"]').datepicker('setEndDate', $(this).val());
        });

        var forexTable = $('#forex-table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: "{{ route('report.currency.data') }}",

                type: "POST",
                data: function(d) {
                    d.base_currency_id = $('select[name=base_currency_id]').val();
                    d.local_currency_id = $('select[name=local_currency_id]').val();
                    d.starting_date = $('input[name=starting_date]').val();
                    d.ending_date = $('input[name=ending_date]').val();

                    d._token = "{{ csrf_token() }}";
                }
            },

            columns: [{
                    data: 'sn',
                    className: 'text-center'
                },
                {
                    data: 'date'
                },
                {
                    data: 'particulars'
                },
                {
                    data: 'vch_type'
                },
                {
                    data: 'vch_no',
                    className: 'text-center'
                },
                {
                    data: 'exch_rate',
                    className: 'text-center'
                },

                {
                    data: 'base_debit',
                    className: 'text-right'
                },
                {
                    data: 'base_credit',
                    className: 'text-right'
                },
                {
                    data: 'local_debit',
                    className: 'text-right'
                },
                {
                    data: 'local_credit',
                    className: 'text-right'
                },

                {
                    data: 'avg_rate',
                    className: 'text-center'
                },
                {
                    data: 'closing_rate',
                    className: 'text-center'
                },
                {
                    data: 'diff',
                    className: 'text-center'
                },

                // Realised
                {
                    data: 'realised',
                    className: 'text-center',
                    render: function(val) {
                        if (!val || val == 0)
                            return '<span class="badge badge-secondary">-</span>';

                        let num = parseFloat(val);
                        let color = num > 0 ? 'success' : 'danger';
                        let sign = num > 0 ? '+' : '-';

                        return `<span class="badge badge-${color}">${sign}${Math.abs(num).toFixed(2)}</span>`;
                    }
                },

                // Unrealised
                {
                    data: 'unrealised',
                    className: 'text-center',
                    render: function(val) {
                        if (!val || val == 0)
                            return '<span class="badge badge-secondary">-</span>';

                        let num = parseFloat(val);
                        let color = num > 0 ? 'info' : 'warning';
                        let sign = num > 0 ? '+' : '-';

                        return `<span class="badge badge-${color}">${sign}${Math.abs(num).toFixed(2)}</span>`;
                    }
                },

                {
                    data: 'remarks'
                },
                {
                    data: null,
                    className: "text-center",
                    orderable: false,
                    render: function(row) {
                        return `
                        <a href="${row.edit_url}" class="btn btn-sm btn-primary">Edit</a>
                        <button class="btn btn-sm btn-danger delete-forex" data-url="${row.delete_url}">
                            Delete
                        </button>
                    `;
                    }
                }

            ],

            dom: '<"row mb-3"lfB>rtip',

            buttons: [{
                    extend: 'excel',
                    footer: true,
                    title: 'Forex Remittance Ledger'
                },
                {
                    extend: 'csv',
                    footer: true,
                    title: 'Forex Remittance Ledger'
                },
                {
                    extend: 'print',
                    footer: true,
                    title: 'Forex Remittance Ledger'
                },
                {
                    extend: 'colvis',
                    footer: false
                }
            ],

            drawCallback: function(settings) {
                var api = this.api();
                var json = api.ajax.json();

                if (json && json.totals) {

                    function colSum(index) {
                        return api.column(index, {
                                page: 'current'
                            })
                            .data()
                            .reduce(function(a, b) {
                                let val = parseFloat((b || '').toString().replace(/[^0-9.-]+/g, ""));
                                return a + (isNaN(val) ? 0 : val);
                            }, 0);
                    }

                    // Footer totals
                    $('#total-base-debit').html(colSum(6).toFixed(2));
                    $('#total-base-credit').html(colSum(7).toFixed(2));
                    $('#total-local-debit').html(colSum(8).toFixed(2));
                    $('#total-local-credit').html(colSum(9).toFixed(2));

                    let rGain = json.totals.realised_gain;
                    let rLoss = json.totals.realised_loss;
                    let uGain = json.totals.unrealised_gain;
                    let uLoss = json.totals.unrealised_loss;
                    let final = json.totals.final_gain_loss;

                    function badge(val) {
                        if (!val || val == 0)
                            return '<span class="badge badge-secondary">-</span>';

                        let num = parseFloat(val);
                        let color = num > 0 ? 'success' : 'danger';
                        let sign = num > 0 ? '+' : '-';

                        return `<span class="badge badge-${color}">${sign}${Math.abs(num).toFixed(2)}</span>`;
                    }

                    $('#total-realised').html(badge(rGain - rLoss));
                    $('#total-unrealised').html(badge(uGain - uLoss));
                    $('#final-gain-loss').html(badge(final));

                    // =========== NET BALANCE (Dr/Cr) ===========
                    let totalDebitUSD = parseFloat($('#total-base-debit').text()) || 0;
                    let totalCreditUSD = parseFloat($('#total-base-credit').text()) || 0;

                    let net = totalCreditUSD - totalDebitUSD;

                    let msg = "";

                    if (net > 0) {
                        msg = `${net.toFixed(2)} USD <strong class="text-success">(Cr)</strong>`;
                    } else if (net < 0) {
                        msg = `${Math.abs(net).toFixed(2)} USD <strong class="text-danger">(Dr)</strong>`;
                    } else {
                        msg = `<strong>0.00 (Nil)</strong>`;
                    }

                    $('#party-net-balance').html(msg);
                }
            }
        });

        $('#filter-btn').on('click', function(e) {
            e.preventDefault();
            forexTable.ajax.reload();
        });

        $(document).on("click", ".delete-forex", function() {
            let url = $(this).data("url");

            if (!confirm("Are you sure you want to delete this remittance?")) return;

            $.post(url, {
                _token: "{{ csrf_token() }}",
                _method: "DELETE"
            }, function(res) {
                forexTable.ajax.reload();
            }).fail(function() {
                alert("Delete failed");
            });
        });
    </script>
@endpush
