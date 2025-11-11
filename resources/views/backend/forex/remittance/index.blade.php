@extends('backend.layout.main')
@section('content')

@if (session()->has('message'))
    <div class="alert alert-success text-center">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        {!! session()->get('message') !!}
    </div>
@endif

<section>
    <div class="container-fluid">
        <a href="{{ route('forex.remittance.create') }}" class="btn btn-info">
            <i class="dripicons-plus"></i> Add New Remittance
        </a>
        <div class="card mt-3">
            <h3 class="text-center mt-3">Filter Forex Remittances</h3>
            <div class="card-body">
                {!! Form::open(['route' => 'forex.remittance.index', 'method' => 'get']) !!}
                <div class="row mt-2">
                    <div class="col-md-3">
                        <label><strong>Date Range</strong></label>
                        <input type="text" class="daterangepicker-field form-control" 
                               value="{{ $starting_date }} To {{ $ending_date }}" required />
                        <input type="hidden" name="start_date" value="{{ $starting_date }}">
                        <input type="hidden" name="end_date" value="{{ $ending_date }}">
                    </div>

                    <div class="col-md-3">
                        <label><strong>Party Type</strong></label>
                        <select name="party_type" class="form-control">
                            <option value="">All</option>
                            <option value="customer">Customer</option>
                            <option value="supplier">Supplier</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label><strong>Currency</strong></label>
                        <select name="currency_id" class="form-control">
                            <option value="">All</option>
                            @foreach ($currencies as $currency)
                                <option value="{{ $currency->id }}">{{ $currency->code }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3 mt-3">
                        <button class="btn btn-primary" type="submit">Filter</button>
                    </div>
                </div>
                {!! Form::close() !!}
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered" id="forex-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference No</th>
                    <th>Party</th>
                    <th>Type</th>
                    <th>Currency</th>
                    <th>USD Amount</th>
                    <th>Local Amount</th>
                    <th>Exchange Rate</th>
                    <th>Remarks</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($remittances as $data)
                    <tr>
                        <td>{{ $data->transaction_date }}</td>
                        <td>{{ $data->reference_no }}</td>
                        <td>
                            @if ($data->party_type == 'customer')
                                {{ optional($data->customer)->name }}
                            @else
                                {{ optional($data->supplier)->name }}
                            @endif
                        </td>
                        <td>{{ ucfirst($data->party_type) }}</td>
                        <td>{{ $data->currency->code ?? '' }}</td>
                        <td>{{ number_format($data->usd_amount, 2) }}</td>
                        <td>{{ number_format($data->local_amount, 2) }}</td>
                        <td>{{ $data->exch_rate }}</td>
                        <td>{{ $data->remarks }}</td>
                        <td>
                            <a href="{{ route('forex.remittance.show', $data->id) }}" class="btn btn-sm btn-primary">View</a>
                            <a href="{{ route('forex.remittance.create', ['edit' => $data->id]) }}" class="btn btn-sm btn-info">Edit</a>
                            {!! Form::open(['route' => ['forex.remittance.destroy', $data->id], 'method' => 'delete', 'class' => 'd-inline']) !!}
                                <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('Delete this remittance?')">Delete</button>
                            {!! Form::close() !!}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="mt-3">
            {{ $remittances->links() }}
        </div>
    </div>
</section>

@endsection
