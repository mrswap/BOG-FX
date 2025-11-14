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
                        <th>Diff</th>
                        <th>Realised Gain/Loss</th>
                        <th>Unrealised Gain/Loss</th>
                        <th>Remarks</th>

                    </tr>
                </thead>
                <tfoot>
                    <tr>
                        <th colspan="6" class="text-right">Totals</th>
                        <th id="total-base-debit"></th>
                        <th id="total-base-credit"></th>
                        <th id="total-local-debit"></th>
                        <th id="total-local-credit"></th>

                        <th colspan="1"></th> <!-- avg rate -->
                        <th colspan="1"></th> <!-- diff -->

                        <th id="total-realised"></th>
                        <th id="total-unrealised"></th>
                        <th id="final-gain-loss"></th>
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
            columns: [{
                    data: 'sn',
                    name: 'sn',
                    className: 'text-center'
                },
                {
                    data: 'date',
                    name: 'date'
                },
                {
                    data: 'particulars',
                    name: 'particulars'
                },
                {
                    data: 'vch_type',
                    name: 'vch_type'
                },
                {
                    data: 'vch_no',
                    name: 'vch_no',
                    className: 'text-center'
                },
                {
                    data: 'exch_rate',
                    name: 'exch_rate',
                    className: 'text-center'
                },

                {
                    data: 'base_debit',
                    name: 'base_debit',
                    className: 'text-right'
                },
                {
                    data: 'base_credit',
                    name: 'base_credit',
                    className: 'text-right'
                },
                {
                    data: 'local_debit',
                    name: 'local_debit',
                    className: 'text-right'
                },
                {
                    data: 'local_credit',
                    name: 'local_credit',
                    className: 'text-right'
                },

                {
                    data: 'avg_rate',
                    name: 'avg_rate',
                    className: 'text-center'
                },
                {
                    data: 'diff',
                    name: 'diff',
                    className: 'text-center'
                },

                // -------- REALISED (Gain/Loss) --------
                {
                    data: 'realised',
                    name: 'realised',
                    className: 'text-center',
                    render: function(val) {
                        if (!val || val == 0) return '<span class="badge badge-secondary">-</span>';

                        let num = parseFloat(val);
                        let color = num > 0 ? 'success' : 'danger';
                        let sign = num > 0 ? '+' : '-';

                        return `<span class="badge badge-${color}">${sign}${Math.abs(num).toFixed(2)}</span>`;
                    }
                },

                // -------- UNREALISED (Gain/Loss) --------
                {
                    data: 'unrealised',
                    name: 'unrealised',
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
                    data: 'remarks',
                    name: 'remarks'
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

                // ===== TOTALS FROM CONTROLLER =====
                if (json && json.totals) {

                    // TOTAL BASE/LOCAL
                    function colSum(index) {
                        return api.column(index, {
                            page: 'current'
                        }).data().reduce((a, b) => {
                            let val = parseFloat((b || '').replace(/[^0-9.-]+/g, ""));
                            return a + (isNaN(val) ? 0 : val);
                        }, 0);
                    }

                    $('#total-base-debit').html(colSum(6).toFixed(2));
                    $('#total-base-credit').html(colSum(7).toFixed(2));
                    $('#total-local-debit').html(colSum(8).toFixed(2));
                    $('#total-local-credit').html(colSum(9).toFixed(2));


                    // ====== REALISED TOTAL ======
                    let rGain = json.totals.realised_gain;
                    let rLoss = json.totals.realised_loss;
                    let uGain = json.totals.unrealised_gain;
                    let uLoss = json.totals.unrealised_loss;
                    let final = json.totals.final_gain_loss;

                    // Apply badges
                    function badge(val) {
                        if (val == 0) return '<span class="badge badge-secondary">-</span>';
                        let num = parseFloat(val);
                        let color = num > 0 ? 'success' : 'danger';
                        let sign = num > 0 ? '+' : '-';
                        return `<span class="badge badge-${color}">${sign}${Math.abs(num).toFixed(2)}</span>`;
                    }

                    $('#total-realised').html(badge(rGain - rLoss));
                    $('#total-unrealised').html(badge(uGain - uLoss));
                    $('#final-gain-loss').html(badge(final));
                }
            }
        });

        // Filter reload
        $('#filter-btn').on('click', function(e) {
            e.preventDefault();
            forexTable.ajax.reload();
        });
    </script>
@endpush
