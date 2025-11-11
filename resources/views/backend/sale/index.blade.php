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
                <thead>
                <tr>
                    <th class="not-exported"></th>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Party</th>
                    <th>Currency</th>
                    <th>USD Amount</th>
                    <th>Local Amount</th>
                    <th>Exchange Rate</th>
                    <th>Gain/Loss</th>
                    <th>Remarks</th>
                    <th class="not-exported">Action</th>
                </tr>
                </thead>
                <tfoot>
                <tr>
                    <th></th>
                    <th>Total</th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
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
    $("ul#forex").siblings('a').attr('aria-expanded', 'true');
    $("ul#forex").addClass("show");

    var all_permission = <?php echo json_encode($all_permission); ?>;
    var starting_date = <?php echo json_encode($starting_date); ?>;
    var ending_date = <?php echo json_encode($ending_date); ?>;

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    $(".daterangepicker-field").daterangepicker({
        callback: function(startDate, endDate, period) {
            var start = startDate.format('YYYY-MM-DD');
            var end = endDate.format('YYYY-MM-DD');
            $(this).val(start + ' To ' + end);
            $('input[name="starting_date"]').val(start);
            $('input[name="ending_date"]').val(end);
        }
    });

    // Initialize DataTable
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
            }
        },
        columns: [
            {data: null, defaultContent: '', orderable: false},
            {data: 'transaction_date'},
            {data: 'reference_no'},
            {data: 'party'},
            {data: 'currency'},
            {data: 'usd_amount'},
            {data: 'local_amount'},
            {data: 'exchange_rate'},
            {data: 'gain_loss'},
            {data: 'remarks'},
            {data: 'options', orderable: false, searchable: false}
        ],
        order: [[1, 'desc']],
        dom: '<"row"lfB>rtip',
        buttons: [
            {extend: 'pdf', footer: true},
            {extend: 'excel', footer: true},
            {extend: 'csv', footer: true},
            {extend: 'print', footer: true},
            {extend: 'colvis'}
        ],
        rowId: 'id',
        drawCallback: function() {
            var api = this.api();
            $(api.column(5).footer()).html(api.column(5, {page:'current'}).data().sum().toFixed(2));
            $(api.column(6).footer()).html(api.column(6, {page:'current'}).data().sum().toFixed(2));
        }
    });

    // Filter button reload
    $('#filter-btn').on('click', function(e) {
        e.preventDefault();
        forexTable.ajax.reload();
    });

    // Show details modal
    function forexDetails(data) {
        var html = '<p><strong>Date:</strong> ' + data.date + '</p>';
        html += '<p><strong>Reference:</strong> ' + data.reference + '</p>';
        html += '<p><strong>Party:</strong> ' + data.party + '</p>';
        html += '<p><strong>Currency:</strong> ' + data.currency + '</p>';
        html += '<p><strong>USD Amount:</strong> ' + data.usd_amount + '</p>';
        html += '<p><strong>Local Amount:</strong> ' + data.local_amount + '</p>';
        html += '<p><strong>Exchange Rate:</strong> ' + data.exchange_rate + '</p>';
        html += '<p><strong>Gain/Loss:</strong> ' + data.gain_loss + '</p>';
        html += '<p><strong>Remarks:</strong> ' + data.remarks + '</p>';

        $('#forex-content').html(html);
        $('#forex-details').modal('show');
    }
</script>
@endpush
