@extends('backend.layout.main')

@section('content')
<div class="container-fluid">
    <h3 class="mt-3 text-center">Forex Report — {{ ucfirst($type) }} Wise</h3>

    <div class="card mt-3">
        <div class="card-body">
            <form id="filterForm" class="form-inline">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">

                <div class="form-group mr-2">
                    <label class="mr-1">Date</label>
                    <input type="text" class="daterangepicker-field form-control" value="{{ $starting_date }} To {{ $ending_date }}" />
                    <input type="hidden" name="starting_date" value="{{ $starting_date }}">
                    <input type="hidden" name="ending_date" value="{{ $ending_date }}">
                </div>

                <div class="form-group mr-2">
                    <label class="mr-1">Party</label>
                    <select name="party_id" class="form-control">
                        <option value="">All</option>
                        @foreach($party_list as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group mr-2">
                    <label class="mr-1">Voucher</label>
                    <select name="voucher_no" class="form-control">
                        <option value="">All</option>
                        @foreach($voucher_list as $v)
                            <option value="{{ $v }}">{{ $v }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group mr-2">
                    <label class="mr-1">Base Currency</label>
                    <select name="currency_id" class="form-control">
                        <option value="0">All</option>
                        @foreach($currency_list as $c)
                            <option value="{{ $c->id }}">{{ $c->code }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group mr-2">
                    <label class="mr-1">Closing Rate (opt.)</label>
                    <input type="text" name="closing_rate_global" id="closing_rate_global" class="form-control" placeholder="e.g. 91.50" />
                </div>

                <button class="btn btn-primary" id="applyFilter" type="submit">Apply</button>
            </form>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body table-responsive">
            <table id="reportTable" class="table table-bordered" style="width:100%">
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
                        <th>Closing Rate</th>
                        <th>Diff</th>
                        <th>Realised</th>
                        <th>Unrealised</th>
                    </tr>
                </thead>
                <tfoot>
                    <tr>
                        <th colspan="6" class="text-right">Totals</th>
                        <th id="total-base-debit"></th>
                        <th id="total-base-credit"></th>
                        <th id="total-local-debit"></th>
                        <th id="total-local-credit"></th>
                        <th></th><th></th><th></th>
                        <th id="total-realised"></th>
                        <th id="total-unrealised"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(".daterangepicker-field").daterangepicker({
    callback: function(startDate, endDate, period) {
        const start = startDate.format('YYYY-MM-DD');
        const end = endDate.format('YYYY-MM-DD');
        $(this).val(start + ' To ' + end);
        $('input[name=starting_date]').val(start);
        $('input[name=ending_date]').val(end);
    }
});

let reportTable = $('#reportTable').DataTable({
    processing: true,
    serverSide: true,
    ajax: {
        url: "{{ route('forex.report.data') }}",
        type: "POST",
        data: function(d) {
            d._token = "{{ csrf_token() }}";
            d.type = $('input[name=type]').val();
            d.currency_id = $('select[name=currency_id]').val();
            d.party_id = $('select[name=party_id]').val();
            d.voucher_no = $('select[name=voucher_no]').val();
            d.starting_date = $('input[name=starting_date]').val();
            d.ending_date = $('input[name=ending_date]').val();
            d.closing_rate_global = $('#closing_rate_global').val();
        }
    },
    columns: [
        {data:'sn'}, {data:'date'}, {data:'party'}, {data:'voucher'}, {data:'voucher_no'},
        {data:'exchange'}, {data:'base_debit'}, {data:'base_credit'}, {data:'local_debit'},
        {data:'local_credit'}, {data:'avg_rate'}, {data:'closing_rate'}, {data:'diff'},
        {
            data:'realised',
            render: function(val){
                if (!val || val == 0) return '<span class="badge badge-secondary">-</span>';
                const num = parseFloat(val); const color = num>0 ? 'success' : 'danger'; const sign = num>0 ? '+' : '-';
                return `<span class="badge badge-${color}">${sign}${Math.abs(num).toFixed(2)}</span>`;
            }
        },
        {
            data:'unrealised',
            render: function(val){
                if (!val || val == 0) return '<span class="badge badge-secondary">-</span>';
                const num = parseFloat(val); const color = num>0 ? 'info' : 'warning'; const sign = num>0 ? '+' : '-';
                return `<span class="badge badge-${color}">${sign}${Math.abs(num).toFixed(2)}</span>`;
            }
        }
    ],
    dom: '<"row mb-3"lfB>rtip',
    buttons: [
        { extend: 'excel', footer: true, title: 'Forex Report' },
        { extend: 'csv', footer: true, title: 'Forex Report' },
        { extend: 'print', footer: true, title: 'Forex Report' },
    ],
    drawCallback: function(settings){
        var api = this.api();
        var json = api.ajax.json();
        if (json && json.totals) {
            function colSum(index){
                return api.column(index,{page:'current'}).data().reduce(function(a,b){
                    let v = (b||'').toString().replace(/[^0-9.-]+/g,'');
                    return a + (isNaN(parseFloat(v)) ? 0 : parseFloat(v));
                },0);
            }
            $('#total-base-debit').html(colSum(6).toFixed(2));
            $('#total-base-credit').html(colSum(7).toFixed(2));
            $('#total-local-debit').html(colSum(8).toFixed(2));
            $('#total-local-credit').html(colSum(9).toFixed(2));

            const rGain = json.totals.realised_gain;
            const rLoss = json.totals.realised_loss;
            const uGain = json.totals.unrealised_gain;
            const uLoss = json.totals.unrealised_loss;
            const final = json.totals.final_gain_loss;

            function badge(val){
                if (!val || val == 0) return '<span class="badge badge-secondary">-</span>';
                const num = parseFloat(val); const color = num>0 ? 'success' : 'danger'; const sign = num>0 ? '+' : '-';
                return `<span class="badge badge-${color}">${sign}${Math.abs(num).toFixed(2)}</span>`;
            }

            $('#total-realised').html(badge(rGain - rLoss));
            $('#total-unrealised').html(badge(uGain - uLoss));
            $('#final-gain-loss').html(badge(final));
        }
    }
});

$('#filterForm').on('submit', function(e){
    e.preventDefault();
    reportTable.ajax.reload();
});
</script>
@endpush
