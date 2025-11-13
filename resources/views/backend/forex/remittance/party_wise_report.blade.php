@extends('backend.layout.main')

@section('content')
<div class="container-fluid">
    <h3 class="mb-4">Party-wise Forex Remittance Report</h3>

    @foreach($reportData as $party => $entries)
        <div class="card mb-5">
            <div class="card-header">
                <h5>{{ $party }}</h5>
                <small>{{ $entries[0]['date'] ?? '' }} to {{ end($entries)['date'] ?? '' }}</small>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Particulars</th>
                            <th>Vch Type</th>
                            <th>Vch No</th>
                            <th>Exch Rate</th>
                            <th>{{ $entries[0]['base_debit'] ? 'Base Debit' : 'Base Credit' }}</th>
                            <th>{{ $entries[0]['base_credit'] ? 'Base Credit' : 'Base Debit' }}</th>
                            <th>{{ $entries[0]['local_debit'] ? 'Local Debit' : 'Local Credit' }}</th>
                            <th>{{ $entries[0]['local_credit'] ? 'Local Credit' : 'Local Debit' }}</th>
                            <th>Avg Rate</th>
                            <th>Diff</th>
                            <th>Realised Gain/Loss</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($entries as $entry)
                        <tr>
                            <td>{{ $entry['date'] }}</td>
                            <td>{{ $entry['particulars'] }}</td>
                            <td>{{ $entry['vch_type'] }}</td>
                            <td>{{ $entry['vch_no'] }}</td>
                            <td>{{ $entry['exchange_rate'] }}</td>
                            <td>{{ number_format($entry['base_debit'], 2) }}</td>
                            <td>{{ number_format($entry['base_credit'], 2) }}</td>
                            <td>{{ number_format($entry['local_debit'], 2) }}</td>
                            <td>{{ number_format($entry['local_credit'], 2) }}</td>
                            <td>{{ number_format($entry['avg_rate'], 4) }}</td>
                            <td>{{ number_format($entry['diff'], 2) }}</td>
                            <td>{{ number_format($entry['realised_gain_loss'], 2) }}</td>
                            <td>{{ $entry['particulars'] }}</td>
                        </tr>
                        @endforeach
                        <tr>
                            <th colspan="5">Total</th>
                            <th>{{ number_format(array_sum(array_column($entries, 'base_debit')),2) }}</th>
                            <th>{{ number_format(array_sum(array_column($entries, 'base_credit')),2) }}</th>
                            <th>{{ number_format(array_sum(array_column($entries, 'local_debit')),2) }}</th>
                            <th>{{ number_format(array_sum(array_column($entries, 'local_credit')),2) }}</th>
                            <th colspan="3"></th>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
@endsection
