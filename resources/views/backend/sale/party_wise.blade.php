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
                <h3 class="text-center mt-3">Filter Forex Remittances</h3>
                <div class="card-body">
                    {!! Form::open(['route' => 'sales.index', 'method' => 'get']) !!}
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
                            <label><strong>Transaction Type</strong></label>
                            <select name="txn_group" class="form-control">
                                <option value="">All</option>
                                <option value="customer_side">Sale + Receipt</option>
                                <option value="supplier_side">Purchase + Payment</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label><strong>Select Party</strong></label>
                            <select name="party_id" class="form-control">
                                <option value="">All</option>
                                @foreach ($party_list as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
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
        <div class="card mb-3 shadow-sm">
            <div class="card-body">

                <div class="row text-center mb-3">

                    <div class="col-md-3">
                        <h6 class="text-muted">Base DR</h6>
                        <h5 id="sum-base-dr" class="text-danger">0.00</h5>
                    </div>

                    <div class="col-md-3">
                        <h6 class="text-muted">Base CR</h6>
                        <h5 id="sum-base-cr" class="text-success">0.00</h5>
                    </div>

                    <div class="col-md-3">
                        <h6 class="text-muted">Local DR</h6>
                        <h5 id="sum-local-dr" class="text-danger">0.00</h5>
                    </div>

                    <div class="col-md-3">
                        <h6 class="text-muted">Local CR</h6>
                        <h5 id="sum-local-cr" class="text-success">0.00</h5>
                    </div>

                </div>

                <hr>

                <div class="text-center">

                    <h5 id="sum-net-balance"></h5>

                    <div style="font-size:14px;" id="sum-net-breakup"></div>

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
                        <th>Manual Remark</th>
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
                        <th></th>
                        <th></th> <!-- remarks -->
                    </tr>
                    <tr>
                        <th colspan="7" class="text-right font-weight-bold">Net Balance</th>
                        <th colspan="11" id="party-net-balance" class="text-left font-weight-bold net-balance-info">
                        </th>
                    </tr>
                    <tr>
                        <th colspan="7" class="text-right font-weight-bold">Local Currency Net</th>
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
        /////////////////////////////////////////////////
        // DATE INPUT UX PATCH (DMY + 2000–2100 STRICT)
        /////////////////////////////////////////////////

        // 1️⃣ While typing: only numbers, auto hyphen, max 8 digits
        $(document).on('input', 'input[name="starting_date"], input[name="ending_date"]', function() {

            let val = $(this).val();

            // allow digits only
            val = val.replace(/\D/g, '');

            // max ddmmyyyy (8 digits)
            val = val.substring(0, 8);

            let d = val.substring(0, 2);
            let m = val.substring(2, 4);
            let y = val.substring(4, 8);

            let out = '';
            if (d) out = d;
            if (m) out += '-' + m;
            if (y) out += '-' + y;

            $(this).val(out);
        });


        // 2️⃣ On blur: validate + normalize year
        $(document).on('blur', 'input[name="starting_date"], input[name="ending_date"]', function() {

            let val = $(this).val();
            if (!val) return;

            let p = val.split('-');
            if (p.length !== 3) {
                $(this).val('');
                return;
            }

            let d = parseInt(p[0], 10);
            let m = parseInt(p[1], 10);
            let y = p[2];

            // expand 1–2 digit year → 2000+
            if (y.length <= 2) {
                y = (2000 + parseInt(y, 10)).toString();
            }

            y = parseInt(y, 10);

            // hard validation
            if (
                d < 1 || d > 31 ||
                m < 1 || m > 12 ||
                y < 2000 || y > 2100
            ) {
                alert('Invalid date. Use dd-mm-yyyy (2000–2100)');
                $(this).val('');
                return;
            }

            // normalize format
            $(this).val(
                String(d).padStart(2, '0') + '-' +
                String(m).padStart(2, '0') + '-' +
                y
            );
        });


        //////////////////////////////////////////
        // FORMAT BREAKUP CHILD ROW
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
                                <th>Settlement Date</th>
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
                <td>${b.settlement_date ?? '-'}</td>
                <td>${Number(b.matched_base).toFixed(2)}</td>
                <td>${Number(b.inv_rate).toFixed(4)}</td>
                <td>${Number(b.settl_rate).toFixed(4)}</td>
                <td class="${color}">${sign}${Math.abs(b.realised).toFixed(2)}</td>
            </tr>
            `;
            });

            html += `
                <tr   tr class="bg-light font-weight-bold">
                            <td colspan="4" class="text-right">Total Realised</td>
                            <td>${Number(row.realised).toFixed(2)}</td>
                        </tr>
                    </tbody>
                </table>`;

            return html;
        }


        //////////////////////////////////////////
        // DATATABLE INITIALIZATION
        //////////////////////////////////////////
        var forexTable = $('#forex-table').DataTable({
            processing: true,
            serverSide: true,
            deferLoading: 0,


            ajax: {
                url: "{{ route('report.party.data') }}",
                type: "POST",
                data: function(d) {
                    d.starting_date = $('input[name=starting_date]').val();
                    d.ending_date = $('input[name=ending_date]').val();
                    d.party_id = $('select[name=party_id]').val();
                    d.txn_group = $('select[name=txn_group]').val(); // ⭐ NEW
                    d._token = "{{ csrf_token() }}";
                }

            },

            //////////////////////////////////////////
            // COLUMNS (FIRST COL = EXPAND BUTTON)
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
                    data: 'date',
                    render: function(val) {
                        if (!val) return "";

                        // Convert Y-m-d → d-m-Y
                        let parts = val.split("-");
                        return parts[2] + "-" + parts[1] + "-" + parts[0];
                    }
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
                    data: 'manual_remark',
                    orderable: false,
                    render: function(data, type, row) {
                        let val = data ? data : '';
                        return `
                                <input type="text"
                                    class="form-control form-control-sm manual-remark-input"
                                    data-id="${row.id}"
                                    value="${val}"
                                    placeholder="Enter remark..." />
                            `;
                    }
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
            //   FOOTER TOTALS + TOP SUMMARY
            //////////////////////////////////////////
            drawCallback: function(settings) {

                var api = this.api();
                var json = api.ajax.json();

                if (!json) return;

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

                // =============================
                // COLUMN TOTALS
                // =============================
                let totalBaseDR = colSum(7);
                let totalBaseCR = colSum(8);
                let totalLocalDR = colSum(9);
                let totalLocalCR = colSum(10);

                // =============================
                // FOOTER UPDATE
                // =============================
                $('#total-base-debit').html(totalBaseDR.toFixed(2));
                $('#total-base-credit').html(totalBaseCR.toFixed(2));
                $('#total-local-debit').html(totalLocalDR.toFixed(2));
                $('#total-local-credit').html(totalLocalCR.toFixed(2));

                // =============================
                // NET BASE CALCULATION
                // =============================
                let netBase = totalBaseCR - totalBaseDR;
                let baseSign = netBase >= 0 ? "Cr" : "Dr";
                let baseColor = netBase >= 0 ? "success" : "danger";

                // =============================
                // GLOBAL JSON (SAFE ACCESS)
                // =============================
                let g = json.global || {
                    local_net: 0,
                    sign: "Nil"
                };

                // =============================
                // BASE BREAKUP HTML
                // =============================
                let baseBreakupHTML = `
                        ${Math.abs(netBase).toFixed(2)} USD 
                        <strong class="text-${baseColor}">(${baseSign})</strong>

                        <div style="font-size: 13px; margin-top: 4px;">
                            <span class="text-danger"><strong>DR:</strong> ${totalBaseDR.toFixed(2)}</span>
                            &nbsp; | &nbsp;
                            <span class="text-success"><strong>CR:</strong> ${totalBaseCR.toFixed(2)}</span>
                            &nbsp; | &nbsp;
                            <strong>Net:</strong> ${g.local_net.toFixed(2)} ${g.sign}
                        </div>
                    `;

                $('#party-net-balance').html(baseBreakupHTML);

                // =============================
                // TOP SUMMARY UPDATE (IF EXISTS)
                // =============================
                if ($('#sum-base-dr').length) {

                    $('#sum-base-dr').html(totalBaseDR.toFixed(2));
                    $('#sum-base-cr').html(totalBaseCR.toFixed(2));
                    $('#sum-local-dr').html(totalLocalDR.toFixed(2));
                    $('#sum-local-cr').html(totalLocalCR.toFixed(2));

                    $('#sum-net-balance').html(`
                    Net Balance:
                    <strong class="text-${baseColor}">
                        ${Math.abs(netBase).toFixed(2)} USD (${baseSign})
                    </strong>
                            `);

                    $('#sum-net-breakup').html(`
                        <span class="text-danger"><strong>DR:</strong> ${totalBaseDR.toFixed(2)}</span>
                        &nbsp; | &nbsp;
                        <span class="text-success"><strong>CR:</strong> ${totalBaseCR.toFixed(2)}</span>
                        &nbsp; | &nbsp;
                        <strong>Net:</strong> ${g.local_net.toFixed(2)} ${g.sign}
                    `);
                }

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
        // EXPANDABLE CHILD ROW CLICK EVENT
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
        // FILTER BUTTON
        //////////////////////////////////////////
        $('#filter-btn').on('click', function(e) {
            e.preventDefault();
            forexTable.ajax.reload();
        });


        //////////////////////////////////////////
        // DELETE FOREX
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
        
        $(document).on('blur', '.manual-remark-input', function() {

            let input = $(this);
            let id = input.data('id');
            let remark = input.val();

            $.ajax({
                url: "{{ route('transactions.update.manual.remark') }}",
                type: "POST",
                data: {
                    _token: "{{ csrf_token() }}",
                    id: id,
                    manual_remark: remark
                },
                success: function() {
                    input.addClass('border-success');
                    setTimeout(() => input.removeClass('border-success'), 1500);
                },
                error: function() {
                    input.addClass('border-danger');
                    setTimeout(() => input.removeClass('border-danger'), 2000);
                }
            });
        });
    </script>
@endpush
