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

            <div class="card mt-3">
                <h3 class="text-center mt-3">Currency Wise Forex Report</h3>
                <div class="card-body">
                    {!! Form::open(['route' => 'forex.txn.report.invoice', 'method' => 'post']) !!}
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


                        <div class="col-md-3">
                            <label><strong>Invoice Number</label>
                            <select name="invoice_id" class="form-control">
                                <option value="all">All</option>
                                @foreach ($transaction as $txn)
                                    <option value="{{ $txn->id }}">{{ $txn->voucher_no }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-2 mt-4 pt-2">
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
                        <th colspan="11" id="party-net-balance" class="text-left font-weight-bold net-balance-info">
                        </th>
                    </tr>
                    
                    <tr>
                        <th colspan="6" class="text-right font-weight-bold">Local Currency Net</th>
                        <th colspan="11" id="local-net-balance" class="text-left font-weight-bold"></th>
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
                url: "{{ route('report.invoice.data') }}",
                type: "POST",
                data: function(d) {

                    d.starting_date = $('input[name=starting_date]').val();
                    d.ending_date = $('input[name=ending_date]').val();

                    d.invoice_id = $('select[name=invoice_id]').val();

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
           drawCallback: function (settings) {

    var api = this.api();
    var json = api.ajax.json();
    if (!json || !json.totals) return;

    function colSum(index) {
        return api.column(index, { page: "current" }).data().reduce(function (a, b) {
            let val = parseFloat((b || "").toString().replace(/[^0-9.-]+/g, ""));
            return a + (isNaN(val) ? 0 : val);
        }, 0);
    }

    // ===========================================
    // BASE CURRENCY TOTALS (USD)
    // ===========================================
    let totalBaseDR = colSum(7);
    let totalBaseCR = colSum(8);

    $('#total-base-debit').html(totalBaseDR.toFixed(2));
    $('#total-base-credit').html(totalBaseCR.toFixed(2));
    $('#total-local-debit').html(colSum(9).toFixed(2));
    $('#total-local-credit').html(colSum(10).toFixed(2));

    // Base Net (USD)
    let netBase = totalBaseCR - totalBaseDR;
    let baseSign = netBase >= 0 ? "(Cr)" : "(Dr)";
    let baseColor = netBase >= 0 ? "success" : "danger";

    let baseBreakupHTML = `
        ${Math.abs(netBase).toFixed(2)} USD 
        <strong class="text-${baseColor}">${baseSign}</strong>

        <div style="font-size: 12px; margin-top: 3px;">
            <span class="text-danger"><strong>DR:</strong> ${totalBaseDR.toFixed(2)}</span>
            &nbsp; | &nbsp;
            <span class="text-success"><strong>CR:</strong> ${totalBaseCR.toFixed(2)}</span>
            &nbsp; | &nbsp;
            <strong>Net:</strong> ${Math.abs(netBase).toFixed(2)} ${netBase >= 0 ? "Cr" : "Dr"}
        </div>
    `;
    $('#party-net-balance').html(baseBreakupHTML);

    // ===========================================
    // LOCAL CURRENCY BREAKUP (VOUCHER-WISE)
    // ===========================================
    let DR_list = [];
    let CR_list = [];
    let DR_total = 0;
    let CR_total = 0;

    api.rows().every(function () {
        let d = this.data();
        let type = d.vch_type.toLowerCase();

        let vno = d.vch_no;
        let ld = parseFloat((d.local_debit || "0").replace(/,/g, ""));
        let lc = parseFloat((d.local_credit || "0").replace(/,/g, ""));

        if ((type === "sale" || type === "payment") && ld > 0) {
            DR_list.push({ vno, amt: ld });
            DR_total += ld;
        }

        if ((type === "receipt" || type === "purchase") && lc > 0) {
            CR_list.push({ vno, amt: lc });
            CR_total += lc;
        }
    });

    let localNet = CR_total - DR_total;
    let localSign = localNet >= 0 ? "(Cr)" : "(Dr)";
    let localColor = localNet >= 0 ? "success" : "danger";

    // ===========================================
    // LOCAL TABLE BREAKUP HTML
    // ===========================================
    let localBreakupHTML = `
        <div style="font-size: 13px;">

            <table class="table table-sm table-bordered mb-2">
                <thead class="thead-light">
                    <tr><th colspan="2" class="text-center">CR — Receipt + Purchase</th></tr>
                    <tr><th>Voucher No</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    ${CR_list.map(x => `
                        <tr>
                            <td>${x.vno}</td>
                            <td class="text-right">${x.amt.toFixed(2)}</td>
                        </tr>
                    `).join("")}
                    <tr class="font-weight-bold bg-light">
                        <td>Total CR</td>
                        <td class="text-right">${CR_total.toFixed(2)}</td>
                    </tr>
                </tbody>
            </table>

            <table class="table table-sm table-bordered mb-2">
                <thead class="thead-light">
                    <tr><th colspan="2" class="text-center">DR — Sale + Payment</th></tr>
                    <tr><th>Voucher No</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    ${DR_list.map(x => `
                        <tr>
                            <td>${x.vno}</td>
                            <td class="text-right">${x.amt.toFixed(2)}</td>
                        </tr>
                    `).join("")}
                    <tr class="font-weight-bold bg-light">
                        <td>Total DR</td>
                        <td class="text-right">${DR_total.toFixed(2)}</td>
                    </tr>
                </tbody>
            </table>

            <table class="table table-sm table-bordered">
                <tbody>
                    <tr class="font-weight-bold">
                        <td>Local Net</td>
                        <td class="text-right text-${localColor}">
                            ${Math.abs(localNet).toFixed(2)} ${localSign}
                        </td>
                    </tr>
                </tbody>
            </table>

        </div>
    `;

    // Inject final HTML into UI
    $('#local-net-balance').html(localBreakupHTML);
}


        });

        $(document).ready(function() {
            $('body').tooltip({
                selector: '.net-balance-info',
                html: true,
                placement: 'top'
            });
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
