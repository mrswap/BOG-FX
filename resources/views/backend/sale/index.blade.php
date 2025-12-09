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
    <style>
        td.details-control {
            background: url("https://cdn-icons-png.flaticon.com/512/32/32195.png") no-repeat center center;
            background-size: 14px;
            cursor: pointer;
        }

        tr.shown td.details-control {
            background: url("https://cdn-icons-png.flaticon.com/512/1828/1828778.png") no-repeat center center;
            background-size: 14px;
        }
    </style>

    <section>
        <div class="container-fluid">
            @if (in_array('forex-add', $all_permission))
                <a href="{{ route('sales.create') }}" class="btn btn-info add-forex-btn">
                    <i class="dripicons-plus"></i> Add Forex Remittance
                </a>
            @endif

            <div class="card mt-3">
                <h3 class="text-center mt-3">Filter Forex Remittances</h3>
                <div class="card-body">
                    {!! Form::open(['route' => 'sales.index', 'method' => 'get']) !!}
                    <div class="row mt-2">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label><strong>Date</strong></label>
                                <input type="text" class="daterangepicker-field form-control"
                                    value="{{ $starting_date }} To {{ $ending_date }}" required />
                                <input type="hidden" name="starting_date" value="{{ $starting_date }}" />
                                <input type="hidden" name="ending_date" value="{{ $ending_date }}" />
                            </div>
                        </div>

                        <div class="col-md-2">
                            <div class="form-group">
                                <label><strong>Party Type</strong></label>
                                <select name="party_type" class="form-control">
                                    <option value="">Both</option>

                                    <option value="customer">Customer</option>
                                    <option value="supplier">Supplier</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <div class="form-group">
                                <label><strong>Currency</strong></label>
                                <select name="currency_id" class="form-control">
                                    <option value="0">All</option>
                                    @foreach ($currency_list as $currency)
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
                        <th></th> <!-- expand -->
                        <th>Sn.</th>
                        <th>Date</th>
                        <th>Particulars</th>
                        <th>Vch Type</th>
                        <th>Vch No</th>
                        <th>Exch Rate</th>
                        <th>Base DR</th>
                        <th>Base CR</th>
                        <th>Local DR</th>
                        <th>Local CR</th>
                        <th>Closing Rate</th>
                        <th>Diff</th>
                        <th>Realised</th>
                        <th>Unrealised</th>
                        <th>Remarks</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>


                <tfoot>
                    <tr>
                        <th></th> <!-- expand -->
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

                        <th></th> <!-- closing rate -->
                        <th></th> <!-- diff -->

                        <th id="total-realised"></th>
                        <th id="total-unrealised"></th>
                        <th id="final-gain-loss"></th>

                        <th></th> <!-- remarks -->
                        <!-- ❌ REMOVE THIS: <th></th> action -->
                    </tr>

                    <tr>
                        <th colspan="6" class="text-right font-weight-bold">Net Balance</th>
                        <th colspan="11" id="party-net-balance" class="text-left font-weight-bold"></th>
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
        //////////////////////////////////////////
        //   DATE RANGE PICKER
        //////////////////////////////////////////
        $(".daterangepicker-field").daterangepicker({
            callback: function(startDate, endDate, period) {
                var starting_date = startDate.format('YYYY-MM-DD');
                var ending_date = endDate.format('YYYY-MM-DD');
                $(this).val(starting_date + ' To ' + ending_date);

                $('input[name="starting_date"]').val(starting_date);
                $('input[name="ending_date"]').val(ending_date);
            }
        });


        //////////////////////////////////////////
        //   FORMAT BREAKUP CHILD ROW
        //////////////////////////////////////////
        function formatBreakup(row) {

            if (!row.realised_breakup || row.realised_breakup.length === 0) {
                return `<div class="p-3"><em>No realised breakup available</em></div>`;
            }

            let html = `
    <table class="table table-sm table-bordered mt-2 mb-2">
        <thead class="thead-light">
            <tr>
                <th>Against Vch</th>
                <th>Matched Base</th>
                <th>Invoice Rate</th>
                <th>Settlement Rate</th>
                <th>Realised</th>
            </tr>
        </thead>
        <tbody>
    `;

            row.realised_breakup.forEach(b => {
                let color = b.realised >= 0 ? 'text-success' : 'text-danger';
                let sign = b.realised >= 0 ? '+' : '-';

                html += `
            <tr>
                <td>${b.match_voucher}</td>
                <td>${Number(b.matched_base).toFixed(2)}</td>
                <td>${Number(b.inv_rate).toFixed(4)}</td>
                <td>${Number(b.settl_rate).toFixed(4)}</td>
                <td class="${color}">${sign}${Math.abs(b.realised).toFixed(2)}</td>
            </tr>
        `;
            });

            html += `
        <tr class="bg-light font-weight-bold">
            <td colspan="4" class="text-right">Total Realised</td>
            <td>${Number(row.realised).toFixed(2)}</td>
        </tr>
    </tbody>
    </table>`;

            return html;
        }


        //////////////////////////////////////////
        //   DATATABLE INITIALIZATION
        //////////////////////////////////////////
        var forexTable = $('#forex-table').DataTable({
            processing: true,
            serverSide: true,

            ajax: {
                url: "{{ route('get.forex.remittance.data') }}",
                type: "POST",
                data: function(d) {
                    d.party_type = $('select[name=party_type]').val();
                    d.currency_id = $('select[name=currency_id]').val();
                    d.starting_date = $('input[name=starting_date]').val();
                    d.ending_date = $('input[name=ending_date]').val();
                    d._token = "{{ csrf_token() }}";
                }
            },

            //////////////////////////////////////////
            //   COLUMNS (FIRST COL = EXPAND BUTTON)
            //////////////////////////////////////////
            columns: [

                {
                    className: 'details-control',
                    orderable: false,
                    data: null,
                    defaultContent: ''
                },

                {
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

                // ❌ AVG RATE REMOVED
                // { data: 'avg_rate', className: 'text-center' },

                {
                    data: 'closing_rate',
                    className: 'text-center'
                },
                {
                    data: 'diff',
                    className: 'text-center'
                },

                {
                    data: 'realised',
                    className: 'text-center',
                    render: function(val) {
                        if (!val || val == 0) return '<span class="badge badge-secondary">-</span>';
                        let num = parseFloat(val);
                        let color = num > 0 ? 'success' : 'danger';
                        let sign = num > 0 ? '+' : '-';
                        return `<span class="badge badge-${color}">${sign}${Math.abs(num).toFixed(2)}</span>`;
                    }
                },

                {
                    data: 'unrealised',
                    className: 'text-center',
                    render: function(val) {
                        if (!val || val == 0) return '<span class="badge badge-secondary">-</span>';
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

            //////////////////////////////////////////
            //   FOOTER TOTALS
            //////////////////////////////////////////
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

                    $('#total-base-debit').html(colSum(7).toFixed(2));
                    $('#total-base-credit').html(colSum(8).toFixed(2));
                    $('#total-local-debit').html(colSum(9).toFixed(2));
                    $('#total-local-credit').html(colSum(10).toFixed(2));

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

                    // NET BALANCE
                    let dr = parseFloat($('#total-base-debit').text()) || 0;
                    let cr = parseFloat($('#total-base-credit').text()) || 0;

                    let net = cr - dr;

                    let msg = "";
                    if (net > 0)
                        msg = `${net.toFixed(2)} USD <strong class="text-success">(Cr)</strong>`;
                    else if (net < 0)
                        msg = `${Math.abs(net).toFixed(2)} USD <strong class="text-danger">(Dr)</strong>`;
                    else
                        msg = `<strong>0.00 (Nil)</strong>`;

                    $('#party-net-balance').html(msg);
                }
            }
        });


        //////////////////////////////////////////
        //   EXPANDABLE CHILD ROW CLICK EVENT
        //////////////////////////////////////////
        $('#forex-table tbody').on('click', 'td.details-control', function() {

            var tr = $(this).closest('tr');
            var row = forexTable.row(tr);

            if (row.child.isShown()) {
                row.child.hide();
                tr.removeClass('shown');
            } else {
                row.child(formatBreakup(row.data())).show();
                tr.addClass('shown');
            }
        });


        //////////////////////////////////////////
        //   FILTER BUTTON
        //////////////////////////////////////////
        $('#filter-btn').on('click', function(e) {
            e.preventDefault();
            forexTable.ajax.reload();
        });


        //////////////////////////////////////////
        //   DELETE FOREX
        //////////////////////////////////////////
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
