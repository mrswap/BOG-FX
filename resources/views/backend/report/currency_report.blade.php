{{-- resources/views/report/currency_sale_report.blade.php --}}
@extends('backend.layout.main')
@section('content')
@if(session()->has('not_permitted'))
  <div class="alert alert-danger alert-dismissible text-center">
    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    {{ session()->get('not_permitted') }}
  </div>
@endif

<section class="forms">
  <div class="container-fluid">
    <div class="card">
      <div class="card-header mt-2">
        <h3 class="text-center">{{ trans('file.Currency Wise Forex : Curruncy wise Gain/Loss Report') }}</h3>
      </div>

      {!! Form::open(['route' => 'report.sale', 'method' => 'post']) !!}
      <div class="row mb-3 product-report-filter container-fluid">
        <div class="col-md-4 mt-3">
          <div class="form-group row">
            <label class="d-tc mt-2"><strong>{{ trans('file.Choose Your Date') }}</strong> &nbsp;</label>
            <div class="d-tc">
              <div class="input-group">
                <input type="text" class="daterangepicker-field form-control" value="{{ $start_date ?? '' }} To {{ $end_date ?? '' }}" required />
                <input type="hidden" name="start_date" value="{{ $start_date ?? '' }}" />
                <input type="hidden" name="end_date" value="{{ $end_date ?? '' }}" />
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-1 mt-3">
          <div class="form-group">
            <button class="btn btn-primary" id="run_report" type="button">{{ trans('file.submit') }}</button>
          </div>
        </div>
      </div>
      {!! Form::close() !!}
    </div>
  </div>

  <div class="table-responsive">
    <table id="currency-sale-report-table" class="table table-hover" style="width:100%">
      <thead>
        <tr>
          <th class="not-exported">SN</th>
          <th>Base Currency</th>
          <th class="text-right">Total Sales (Base)</th>
          <th>Invoice Currency</th>
          <th class="text-right">Total Invoice Amount</th>
          <th class="text-right">Avg Sales Exch. Rate</th>
          <th class="text-right">Total Payments (Base)</th>
          <th class="text-right">Total Payments (Local)</th>
          <th class="text-right">Avg Payment Rate</th>
          <th class="text-right">Due</th>
          <th class="text-right">Gain/Loss (numeric)</th>
          <th class="text-right">Gain/Loss</th>
        </tr>
      </thead>

      <tfoot class="tfoot active">
        <tr>
          <th></th><th>{{ trans('file.Total') }}</th>
          <th></th><th></th><th></th><th></th>
          <th></th><th></th><th></th><th></th><th></th><th></th>
        </tr>
      </tfoot>
    </table>
  </div>
</section>
@endsection

@push('scripts')
<script type="text/javascript">
  $.ajaxSetup({
    headers: {'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')}
  });

  // init daterange picker
  $(".daterangepicker-field").daterangepicker({
    callback: function(startDate, endDate){
      var start_date = startDate.format('YYYY-MM-DD');
      var end_date = endDate.format('YYYY-MM-DD');
      $(this).val(start_date + ' To ' + end_date);
      $("input[name=start_date]").val(start_date);
      $("input[name=end_date]").val(end_date);
    }
  });

  var table = null;

  // column indexes (0-based)
  var colTotalSalesBase = 2;
  var colTotalInvoiceAmount = 4;
  var colTotalPaymentsLocal = 7;
  var colTotalPaymentsBase = 6;
  var colDue = 9;
  var colGainLossNumeric = 10;
  var colGainLossHTML = 11;

  function initTable() {
    if ($.fn.DataTable.isDataTable('#currency-sale-report-table')) {
      $('#currency-sale-report-table').DataTable().destroy();
      $('#currency-sale-report-table tbody').empty();
    }

    table = $('#currency-sale-report-table').DataTable({
      processing: true,
      serverSide: true,
      ajax: {
        url: "{{ route('report.sale_curruncy_report_data') }}",

        type: "POST",
        data: function(d) {
          d._token = '{{ csrf_token() }}';
          d.start_date = $('input[name="start_date"]').val();
          d.end_date = $('input[name="end_date"]').val();
        }
      },
      columns: [
        { data: 'sn' },
        { data: 'base_currency' },
        { data: 'total_sales_base', className: 'text-right' },
        { data: 'invoice_currency' },
        { data: 'total_invoice_amount', className: 'text-right' },
        { data: 'avg_sales_exchange_rate', className: 'text-right' },
        { data: 'total_payments_base', className: 'text-right' },
        { data: 'total_payments_local', className: 'text-right' },
        { data: 'avg_payment_exchange_rate', className: 'text-right' },
        { data: 'total_due', className: 'text-right' },
        { data: 'gain_loss_numeric', visible: false, searchable: false },
        { data: 'gain_loss_html', className: 'text-right', orderable: false }
      ],
      order: [[1,'asc']],
      dom: '<"row"lfB>rtip',
      buttons: [
        { extend: 'pdf', title: 'Currency Sale Report', exportOptions: { columns: ':visible:not(.not-exported)' } },
        { extend: 'csv', title: 'Currency Sale Report', exportOptions: { columns: ':visible:not(.not-exported)' } },
        { extend: 'excel', title: 'Currency Sale Report', exportOptions: { columns: ':visible:not(.not-exported)' } },
        { extend: 'print', title: 'Currency Sale Report', exportOptions: { columns: ':visible:not(.not-exported)' } },
        { extend: 'colvis', text: '{{ trans("file.Column visibility") }}' }
      ],
      drawCallback: function() { datatable_sum(table, false); },
      initComplete: function() { datatable_sum(table, false); }
    });
  }

  initTable();

  $('#run_report').on('click', function(){ table.ajax.reload(); });

  function datatable_sum(dt, is_calling_first) {
    try {
      var rows = is_calling_first && dt.rows('.selected').any() ? dt.rows('.selected').indexes() : null;
      function sum(col) {
        var data = rows ? dt.cells(rows, col, { page: 'current' }).data() : dt.column(col, { page: 'current' }).data();
        var total = 0; data.each(function(v){ total += parseFloat(String(v).replace(/,/g,'')) || 0; });
        return total;
      }
      $(dt.column(colTotalSalesBase).footer()).html(sum(colTotalSalesBase).toFixed(2));
      $(dt.column(colTotalInvoiceAmount).footer()).html(sum(colTotalInvoiceAmount).toFixed(2));
      $(dt.column(colTotalPaymentsBase).footer()).html(sum(colTotalPaymentsBase).toFixed(2));
      $(dt.column(colTotalPaymentsLocal).footer()).html(sum(colTotalPaymentsLocal).toFixed(2));
      $(dt.column(colDue).footer()).html(sum(colDue).toFixed(2));
      var gl = sum(colGainLossNumeric), html;
      if (gl > 0) html = '<span class="text-success">+' + gl.toFixed(2) + '</span>';
      else if (gl < 0) html = '<span class="text-danger">-' + Math.abs(gl).toFixed(2) + '</span>';
      else html = '<span class="text-muted">0.00</span>';
      $(dt.column(colGainLossHTML).footer()).html(html);
    } catch (e) { console.error(e); }
  }
</script>
@endpush
