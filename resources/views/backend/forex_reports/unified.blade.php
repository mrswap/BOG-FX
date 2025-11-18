@extends('backend.layout.main')

@section('content')
<div class="container-fluid">

    <h3 class="mt-3 text-center">
        Forex Report — {{ ucfirst($type) }} Wise
    </h3>

    {{-- FILTERS --}}
    <div class="card mt-4">
        <div class="card-body">

            <form id="filterForm">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">

                <div class="row">

                    <div class="col-md-3">
                        <label>Date Range</label>
                        <input type="text" class="daterangepicker-field form-control"
                               value="{{ date('Y-m-01') }} To {{ date('Y-m-d') }}" />
                        <input type="hidden" name="starting_date" value="{{ date('Y-m-01') }}">
                        <input type="hidden" name="ending_date" value="{{ date('Y-m-d') }}">
                    </div>

                    <div class="col-md-3">
                        <label>Base Currency</label>
                        <select class="form-control" name="currency_id">
                            <option value="0">All</option>
                            @foreach ($currency_list as $c)
                                <option value="{{ $c->id }}">{{ $c->code }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2 mt-4">
                        <button type="submit" class="btn btn-primary mt-2">
                            Apply Filter
                        </button>
                    </div>

                </div>
            </form>

        </div>
    </div>

    {{-- TABLE --}}
    <div class="card mt-3">
        <div class="card-body">
            <table id="reportTable" class="table table-bordered">
                <thead class="thead-dark">
                    <tr>
                        <th>Sn</th>
                        <th>Date</th>
                        <th>Party</th>
                        <th>Voucher</th>
                        <th>Voucher No</th>
                        <th>Exchange Rate</th>
                        <th>Base Debit</th>
                        <th>Base Credit</th>
                        <th>Local Debit</th>
                        <th>Local Credit</th>
                        <th>Avg Rate</th>
                        <th>Diff</th>
                        <th>Realised G/L</th>
                        <th>Unrealised G/L</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
     $(".daterangepicker-field").daterangepicker({
            callback: function(startDate, endDate, period) {
                var starting_date = startDate.format('YYYY-MM-DD');
                var ending_date = endDate.format('YYYY-MM-DD');
                var title = starting_date + ' To ' + ending_date;
                $(this).val(title);
                $('input[name="starting_date"]').val(starting_date);
                $('input[name="ending_date"]').val(ending_date);
            }
        });
let table = $('#reportTable').DataTable({
    processing: true,
    serverSide: true,
    ajax: {
        url: "{{ route('forex.report.data') }}",
        type: "POST",
        data: function(d){
            d._token = "{{ csrf_token() }}";
            d.type = $('input[name=type]').val();
            d.currency_id = $('select[name=currency_id]').val();
            d.starting_date = $('input[name=starting_date]').val();
            d.ending_date = $('input[name=ending_date]').val();
        }
    },
    columns: [
        {data: 'sn'},
        {data: 'date'},
        {data: 'party'},
        {data: 'voucher'},
        {data: 'voucher_no'},
        {data: 'exchange'},
        {data: 'base_debit'},
        {data: 'base_credit'},
        {data: 'local_debit'},
        {data: 'local_credit'},
        {data: 'avg_rate'},
        {data: 'diff'},
        {data: 'realised'},
        {data: 'unrealised'},
    ]
});

$('#filterForm').on('submit', function(e){
    e.preventDefault();
    table.ajax.reload();
});
</script>
@endpush
