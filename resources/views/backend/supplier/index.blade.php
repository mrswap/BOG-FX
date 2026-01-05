@extends('backend.layout.main')
@section('content')

@if(session()->has('not_permitted'))
  <div class="alert alert-danger alert-dismissible text-center">
    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    {{ session()->get('not_permitted') }}
  </div>
@endif
@if(session()->has('message'))
  <div class="alert alert-success alert-dismissible text-center">
    <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    {!! session()->get('message') !!}
  </div>
@endif

<section>
  <div class="container-fluid">
    @if(in_array("suppliers-add", $all_permission))
      <a href="{{ route('supplier.create') }}" class="btn btn-info">
        <i class="dripicons-plus"></i> Add Party
      </a>
    @endif
  </div>

  <div class="table-responsive">
    <table id="party-table" class="table">
      <thead>
        <tr>
          <th class="not-exported"></th>
          <th>Party Details</th>
          <th class="not-exported">{{ trans('file.action') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($parties as $key => $party)
        <tr data-id="{{ $party->id }}">
          <td>{{ $key+1 }}</td>
          
          <td>
            <strong>{{ $party->name }}</strong><br>
            {{ $party->company_name }}<br>
            @if($party->vat_number) VAT: {{ $party->vat_number }}<br>@endif
            {{ $party->email }}<br>
            {{ $party->phone }}<br>
            {{ $party->address }}, {{ $party->city }}
            @if($party->state), {{ $party->state }}@endif
            @if($party->postal_code), {{ $party->postal_code }}@endif
            @if($party->country), {{ $party->country }}@endif
          </td>
          <td>
            <div class="btn-group">
              <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown">
                {{ trans('file.action') }}
              </button>
              <ul class="dropdown-menu dropdown-menu-right">
                @if(in_array("suppliers-edit", $all_permission))
                  <li><a href="{{ route('supplier.edit', $party->id) }}" class="btn btn-link"><i class="dripicons-document-edit"></i> {{ trans('file.edit') }}</a></li>
                @endif
                @if(in_array("suppliers-delete", $all_permission))
                  {{ Form::open(['route' => ['supplier.destroy', $party->id], 'method' => 'DELETE']) }}
                  <li><button type="submit" class="btn btn-link" onclick="return confirm('Are you sure?')"><i class="dripicons-trash"></i> {{ trans('file.delete') }}</button></li>
                  {{ Form::close() }}
                @endif
              </ul>
            </div>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</section>

@endsection

@push('scripts')
<script type="text/javascript">
  $("ul#people").siblings('a').attr('aria-expanded','true');
  $("ul#people").addClass("show");
  $("ul#people #supplier-list-menu").addClass("active");

  $('#party-table').DataTable({
      "order": [],
      'language': {
          'lengthMenu': '_MENU_ {{trans("file.records per page")}}',
          "info": '<small>{{trans("file.Showing")}} _START_ - _END_ (_TOTAL_)</small>',
          "search": '{{trans("file.Search")}}',
          'paginate': {'previous': '<i class="dripicons-chevron-left"></i>', 'next': '<i class="dripicons-chevron-right"></i>'}
      }
  });
</script>
@endpush
