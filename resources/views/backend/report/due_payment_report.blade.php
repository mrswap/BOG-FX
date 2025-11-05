{{-- resources/views/report/all_reports.blade.php --}}
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
        <h3 class="text-center">Forex Gain/Lose Due Payment Report</h3>
      </div>

      {!! Form::open(['route' => 'report.due', 'method' => 'post']) !!}
      <div class="row mb-3 product-report-filter container-fluid">

        <div class="col-md-4 mt-3">
          <div class="form-group row">
            <label class="d-tc mt-2"><strong>{{ trans('file.Choose Your Date') }}</strong> &nbsp;</label>
            <div class="d-tc">
              <div class="input-group">
                <input type="text" class="daterangepicker-field form-control" value="{{ $start_date }} To {{ $end_date }}" required />
                <input type="hidden" name="start_date" value="{{ $start_date }}" />
                <input type="hidden" name="end_date" value="{{ $end_date }}" />
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
    <table id="product-report-table" class="table table-hover" style="width:100%">
      <thead>
        <tr>
          <th class="not-exported"></th>
          <th>Date</th>
          <th>Invoice</th>
          <th>Customer</th>
          <th>Base (Code)</th>
          <th>Base Amount</th>
          <th>Invoice Curr</th>
          <th>Invoice Rate</th>
          <th>Invoice Local</th>
          <th>Payments Local</th>
          <th>Payment Currencies (code@rate)</th>
          <th>Due</th>
          <th class="text-right">Gain/Loss (numeric)</th>
          <th class="text-right">Gain/Loss</th>
        </tr>
      </thead>

      <tfoot class="tfoot active">
        <tr>
          <th></th><th></th><th></th><th>{{ trans('file.Total') }}</th>
          <th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th><th></th>
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

  // column indexes for summation
  var colBaseAmount = 5;
  var colInvoiceLocal = 8;
  var colPaymentsLocal = 9;
  var colDue = 11;
  var colGainLossNumeric = 12;
  var colGainLossHTML = 13;

  function initTable() {
    if ($.fn.DataTable.isDataTable('#product-report-table')) {
      $('#product-report-table').DataTable().destroy();
      $('#product-report-table tbody').empty();
    }

    table = $('#product-report-table').DataTable({
      processing: true,
      serverSide: true,
      ajax: {
        
        url: "{{ route('report.sale_due_report_data') }}",
        type: "POST",
        data: function(d) {
          d._token = '{{ csrf_token() }}';
          d.start_date = $('input[name="start_date"]').val();
          d.end_date = $('input[name="end_date"]').val();
          d.report_type = 'due'; // fixed mode now
        }
      },
      columns: [
        { data: null, defaultContent: '', orderable: false, searchable: false },
        { data: 'date' },
        { data: 'label' },
        { data: 'customer' },
        { data: 'base_currency_code' },
        { data: 'base_amount', className: 'text-right' },
        { data: 'invoice_currency_code' },
        { data: 'invoice_exchange_rate', className: 'text-right' },
        { data: 'invoice_local', className: 'text-right' },
        { data: 'payments_local', className: 'text-right' },
        { data: 'payments_currency_summary' },
        { data: 'due_amount', className: 'text-right' },
        { data: 'gain_loss', visible: false, searchable: false },
        { data: 'gain_loss_html', className: 'text-right', orderable: false }
      ],
      order: [[1,'desc']],
      dom: '<"row"lfB>rtip',
      buttons: [
        { extend: 'pdf', title: 'Sale Report', exportOptions: { columns: ':visible:not(.not-exported)' } },
        { extend: 'csv', title: 'Sale Report', exportOptions: { columns: ':visible:not(.not-exported)' } },
        { extend: 'excel', title: 'Sale Report', exportOptions: { columns: ':visible:not(.not-exported)' } },
        { extend: 'print', title: 'Sale Report', exportOptions: { columns: ':visible:not(.not-exported)' } },
        { extend: 'colvis', text: '{{ trans("file.Column visibility") }}' }
      ],
      createdRow: function(row, data) {
        if (data.payments_html) {
          $(row).addClass('has-child').attr('data-child', data.payments_html);
        }
      },
      drawCallback: function() { datatable_sum(table, false); },
      initComplete: function() { datatable_sum(table, false); }
    });
  }

  // first load
  initTable();

  // reload on click
  $('#run_report').on('click', function(){ table.ajax.reload(); });

  // expand payment details on row click
  $('#product-report-table tbody').on('click', 'tr.has-child', function() {
    var tr = $(this), row = table.row(tr);
    if (row.child.isShown()) { row.child.hide(); tr.removeClass('shown'); }
    else { row.child(tr.data('child')).show(); tr.addClass('shown'); }
  });

  function datatable_sum(dt, is_calling_first) {
    try {
      var rows = is_calling_first && dt.rows('.selected').any() ? dt.rows('.selected').indexes() : null;
      function sum(col) {
        var data = rows ? dt.cells(rows, col, { page: 'current' }).data() : dt.column(col, { page: 'current' }).data();
        var total = 0; data.each(function(v){ total += parseFloat(String(v).replace(/,/g,'')) || 0; });
        return total;
      }
      $(dt.column(colBaseAmount).footer()).html(sum(colBaseAmount).toFixed(2));
      $(dt.column(colInvoiceLocal).footer()).html(sum(colInvoiceLocal).toFixed(2));
      $(dt.column(colPaymentsLocal).footer()).html(sum(colPaymentsLocal).toFixed(2));
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
