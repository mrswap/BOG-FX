@extends('backend.layout.main')

@section('content')
    @if (session()->has('not_permitted'))
        <div class="alert alert-danger alert-dismissible text-center">
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
            {{ session()->get('not_permitted') }}
        </div>
    @endif

    <section>
        <div class="container-fluid">

            {{-- ================= FILTER CARD ================= --}}
            <div class="card mt-3">
                <h3 class="text-center mt-3">Exchange Rates Report</h3>

                <div class="card-body">
                    <div class="row mt-2">

                        <div class="col-md-3">
                            <label><strong>From Date</strong></label>
                            <input type="text" name="starting_date" class="form-control datepicker" autocomplete="off"
                                required>
                        </div>

                        <div class="col-md-3">
                            <label><strong>To Date</strong></label>
                            <input type="text" name="ending_date" class="form-control datepicker" autocomplete="off"
                                required>
                        </div>

                        <div class="col-md-3">
                            <label><strong>Base Currency</strong></label>
                            <select name="base_currency_id" class="form-control selectpicker" data-live-search="true">
                                <option value="">All</option>
                                @foreach ($baseCurrencies as $c)
                                    <option value="{{ $c->id }}">{{ $c->code }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label><strong>Local Currency</strong></label>
                            <select name="local_currency_id" class="form-control selectpicker" data-live-search="true">
                                <option value="">All</option>
                                @foreach ($localCurrencies as $c)
                                    <option value="{{ $c->id }}">{{ $c->code }}</option>
                                @endforeach
                            </select>
                        </div>

                    </div>

                    <div class="text-center mt-3">
                        <button class="btn btn-primary" id="filter-btn">Submit</button>
                    </div>
                </div>
            </div>

            {{-- ================= TABLE ================= --}}
            <div class="table-responsive mt-3">
                <table id="exchange-rate-table" class="table table-bordered" style="width:100%">
                    <thead class="thead-dark">
                        <tr>
                            <th class="text-center">Sn</th>
                            <th class="text-center">Date</th>
                            <th>Rates (Day)</th>
                            <th class="text-right">Average Rate</th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr class="bg-light font-weight-bold">
                            <th colspan="3" class="text-right">Overall Average</th>
                            <th id="overall-avg" class="text-right"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

        </div>
    </section>
@endsection
@push('scripts')
    <script>
        /////////////////////////////////////////////////
        // DATE INPUT UX PATCH (DMY + 2000–2100 STRICT)
        /////////////////////////////////////////////////
        $(document).on('input', 'input[name="starting_date"], input[name="ending_date"]', function() {

            let val = $(this).val().replace(/\D/g, '').substring(0, 8);

            let d = val.substring(0, 2);
            let m = val.substring(2, 4);
            let y = val.substring(4, 8);

            let out = '';
            if (d) out = d;
            if (m) out += '-' + m;
            if (y) out += '-' + y;

            $(this).val(out);
        });

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
            let y = p[2].length <= 2 ? 2000 + parseInt(p[2], 10) : parseInt(p[2], 10);

            if (d < 1 || d > 31 || m < 1 || m > 12 || y < 2000 || y > 2100) {
                alert('Invalid date. Use dd-mm-yyyy (2000–2100)');
                $(this).val('');
                return;
            }

            $(this).val(
                String(d).padStart(2, '0') + '-' +
                String(m).padStart(2, '0') + '-' +
                y
            );
        });

        /////////////////////////////////////////////////
        // DATATABLE
        /////////////////////////////////////////////////
        var rateTable = $('#exchange-rate-table').DataTable({
            processing: true,
            serverSide: true,
            deferLoading: 0,

            ajax: {
                url: "{{ route('forex.txn.report.data.exchange_rates') }}",
                type: "POST",
                data: function(d) {
                    d.starting_date = $('input[name=starting_date]').val();
                    d.ending_date = $('input[name=ending_date]').val();
                    d.base_currency_id = $('select[name=base_currency_id]').val();
                    d.local_currency_id = $('select[name=local_currency_id]').val();
                    d._token = "{{ csrf_token() }}";
                }
            },

            columns: [{
                    data: 'sn',
                    className: 'text-center'
                },
                {
                    data: 'date',
                    className: 'text-center'
                },
                {
                    data: 'entries',
                    render: function(entries) {
                        let html = '';
                        entries.forEach(e => {
                            html += `
                <div>
                    <strong>${e.voucher_type}</strong>
                    (${e.voucher_no})
                    : ${e.rate}
                </div>
            `;
                        });
                        return html;
                    }
                },

                {
                    data: 'avg_rate',
                    className: 'text-right font-weight-bold'
                }
            ],

            dom: '<"row mb-3"lfB>rtip',

            buttons: [{
                    extend: 'excel',
                    title: 'Exchange Rates Report'
                },
                {
                    extend: 'csv',
                    title: 'Exchange Rates Report'
                },
                {
                    extend: 'print',
                    title: 'Exchange Rates Report'
                }
            ],

            drawCallback: function() {

                var api = this.api();
                var avgSum = 0;
                var count = 0;

                api.column(3, {
                    page: 'current'
                }).data().each(function(val) {
                    let n = parseFloat(val);
                    if (!isNaN(n)) {
                        avgSum += n;
                        count++;
                    }
                });

                let overall = count > 0 ? (avgSum / count) : 0;
                $('#overall-avg').html(overall.toFixed(6));
            }
        });

        /////////////////////////////////////////////////
        // FILTER BUTTON
        /////////////////////////////////////////////////
        $('#filter-btn').on('click', function(e) {
            e.preventDefault();
            rateTable.ajax.reload();
        });
    </script>
@endpush
