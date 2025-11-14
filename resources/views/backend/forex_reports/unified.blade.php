@extends('backend.layout.main')

@section('content')

@php
    $titles = [
        'invoice' => 'Invoice Wise Forex Report',
        'party' => 'Party Wise Forex Report',
        'base' => 'Base Currency Wise Report',
        'local' => 'Local Currency Wise Report',
        'realised' => 'Realised Forex Gain/Loss Report',
        'unrealised' => 'Unrealised Forex Gain/Loss Report'
    ];
@endphp

<div class="container-fluid">
    <h3 class="text-center mt-3">{{ $titles[$type] ?? 'Forex Report' }}</h3>

    <div class="card">
        <div class="card-body">

            <form method="POST" id="report-form" action="{{ route('forex.report',$type) }}">
                @csrf

                <div class="row mt-2">

                    {{-- DATE RANGE --}}
                    <div class="col-md-3">
                        <label>Date</label>
                        <input type="text" class="daterangepicker-field form-control"
                               value="{{ date('Y-m-01') }} To {{ date('Y-m-d') }}" />
                        <input type="hidden" name="starting_date" value="{{ date('Y-m-01') }}" />
                        <input type="hidden" name="ending_date" value="{{ date('Y-m-d') }}" />
                    </div>

                    {{-- PARTY FILTER (Only For Party Wise) --}}
                    @if($type == 'party')
                    <div class="col-md-3">
                        <label>Party</label>
                        <select name="party_id" class="form-control">
                            @foreach(App\Models\Party::all() as $p)
                                <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    {{-- CURRENCY FILTER (base/local) --}}
                    @if(in_array($type,['base','local']))
                    <div class="col-md-3">
                        <label>Currency</label>
                        <select name="currency_id" class="form-control">
                            @foreach(App\Models\Currency::all() as $c)
                                <option value="{{ $c->id }}">{{ $c->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif

                    <div class="col-md-2 mt-4">
                        <button class="btn btn-primary" type="submit" id="filter-btn">Submit</button>
                    </div>
                </div>

            </form>

        </div>
    </div>

    {{-- TABLE --}}
    <div class="table-responsive mt-3">
        <table id="forex-table" class="table table-bordered">
            <thead class="thead-dark">
                <tr>
                    <th>Sn</th>
                    <th>Date</th>
                    <th>Particulars</th>
                    <th>Vch Type</th>
                    <th>Vch No</th>
                    <th>Rate</th>
                    <th>Base Dr</th>
                    <th>Base Cr</th>
                    <th>Local Dr</th>
                    <th>Local Cr</th>
                    <th>Avg</th>
                    <th>Diff</th>
                    <th>Realised</th>
                    <th>Unrealised</th>
                    <th>Remarks</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

@endsection

@push('scripts')
<script>
    let table = $('#forex-table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: "{{ route('forex.report',$type) }}",
            type: "POST",
            data: function(d){
                d._token = "{{ csrf_token() }}";
                d.starting_date = $('input[name=starting_date]').val();
                d.ending_date = $('input[name=ending_date]').val();

                // dynamic
                d.party_id = $('select[name=party_id]').val();
                d.currency_id = $('select[name=currency_id]').val();
            }
        },
        columns: [
            {data:'sn'},
            {data:'date'},
            {data:'particulars'},
            {data:'vch_type'},
            {data:'vch_no'},
            {data:'exch_rate'},
            {data:'base_debit'},
            {data:'base_credit'},
            {data:'local_debit'},
            {data:'local_credit'},
            {data:'avg_rate'},
            {data:'diff'},
            {data:'realised'},
            {data:'unrealised'},
            {data:'remarks'},
        ]
    });

    $('#filter-btn').click(function(e){
        e.preventDefault();
        table.ajax.reload();
    });
</script>
@endpush
