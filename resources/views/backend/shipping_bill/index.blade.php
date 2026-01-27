@extends('backend.layout.main')

@section('content')
    @if (session()->has('success'))
        <div class="alert alert-success alert-dismissible text-center">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session()->get('success') }}
        </div>
    @endif

    <section>
        <div class="container-fluid mb-3">
            <a href="{{ route('shipping.bill.create') }}" class="btn btn-info">
                <i class="dripicons-plus"></i> Add Shipping Bill
            </a>
        </div>

        <div class="table-responsive">
            <table id="shipping-bill-table" class="table table-bordered">
                <thead>
                    <tr>
                        <th class="not-exported"></th>

                        <th>Export Invoice</th>
                        <th>Invoice Date</th>
                        <th>USD Inv Amount</th>

                        <th>Shipping Bill</th>
                        <th>SB Date</th>
                        <th>Port</th>

                        <th>FOB Value</th>
                        <th>Freight + Insurance</th>

                        <th>IGST Value</th>
                        <th>IGST %</th>

                        <th>DDB</th>
                        <th>RODTEP</th>

                        <th>Status</th>
                        <th class="not-exported">Action</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($bills as $key => $b)
                        <tr>
                            <td></td>

                            <td>{{ $b->export_invoice_no }}</td>
                            <td>{{ optional($b->invoice_date)->format('d-m-Y') }}</td>
                            <td>{{ number_format($b->usd_invoice_amount, 2) }}</td>

                            <td>{{ $b->shipping_bill_no }}</td>
                            <td>{{ optional($b->shipping_bill_date)->format('d-m-Y') }}</td>
                            <td>{{ $b->port }}</td>

                            <td>{{ number_format($b->fob_value, 2) }}</td>
                            <td>{{ number_format($b->freight_insurance, 2) }}</td>

                            <td>{{ number_format($b->igst_value, 2) }}</td>
                            <td>{{ $b->igst_rate }}%</td>

                            <td>{{ number_format($b->ddb, 2) }}</td>
                            <td>{{ number_format($b->rodtep, 2) }}</td>

                            <td>
                                <span class="badge badge-{{ $b->status == 'paid' ? 'success' : 'warning' }}">
                                    {{ ucfirst($b->status) }}
                                </span>
                            </td>

                            <td>
                                <a href="{{ route('shipping.bill.edit', $b->id) }}" class="btn btn-sm btn-primary">
                                    Edit
                                </a>

                                <form action="{{ route('shipping.bill.destroy', $b->id) }}" method="POST"
                                    style="display:inline-block"
                                    onsubmit="return confirm('Are you sure you want to delete this Shipping Bill?')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-danger">
                                        Delete
                                    </button>
                                </form>
                            </td>


                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
@push('scripts')
    <script>
        $('#shipping-bill-table').DataTable({
            order: [],
            language: {
                lengthMenu: '_MENU_ records per page',
                info: '<small>Showing _START_ - _END_ (_TOTAL_)</small>',
                search: 'Search',
                paginate: {
                    previous: '<i class="dripicons-chevron-left"></i>',
                    next: '<i class="dripicons-chevron-right"></i>'
                }
            },
            columnDefs: [{
                    orderable: false,
                    targets: [0, 14]
                },
                {
                    render: function(data, type, row, meta) {
                        if (type === 'display') {
                            data =
                                '<div class="checkbox"><input type="checkbox" class="dt-checkboxes"><label></label></div>';
                        }
                        return data;
                    },
                    checkboxes: {
                        selectRow: true,
                        selectAllRender: '<div class="checkbox"><input type="checkbox" class="dt-checkboxes"><label></label></div>'
                    },
                    targets: [0]
                }
            ],
            select: {
                style: 'multi',
                selector: 'td:first-child'
            },
            lengthMenu: [
                [10, 25, 50, -1],
                [10, 25, 50, "All"]
            ],
            dom: '<"row"lfB>rtip',
            buttons: [{
                    extend: 'excel',
                    text: '<i class="dripicons-document-new"></i>'
                },
                {
                    extend: 'csv',
                    text: '<i class="fa fa-file-text-o"></i>'
                },
                {
                    extend: 'print',
                    text: '<i class="fa fa-print"></i>'
                },
                {
                    extend: 'colvis',
                    text: '<i class="fa fa-eye"></i>'
                },
            ],
        });
    </script>
@endpush
