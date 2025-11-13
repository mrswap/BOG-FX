@extends('backend.layout.main')
@section('content')

@if(session()->has('not_permitted'))
  <div class="alert alert-danger text-center">{{ session()->get('not_permitted') }}</div>
@endif

<section class="forms">
  <div class="container-fluid">
    <div class="row">
      <div class="col-md-12">
        <div class="card">
          <div class="card-header d-flex align-items-center">
            <h4>{{ trans('file.Add Party') }}</h4>
          </div>
          <div class="card-body">
            <p class="italic"><small>{{ trans('file.The field labels marked with * are required input fields') }}.</small></p>
            {!! Form::open(['route' => 'supplier.store', 'method' => 'post', 'files' => true]) !!}
            <div class="row">

              {{-- Party Type --}}
              <div class="col-md-4">
                <label>{{ trans('file.Party Type') }} *</label>
                <select name="type" required class="form-control selectpicker" title="Select Party Type">
                  <option value="customer">Customer</option>
                  <option value="supplier">Supplier</option>
                  <option value="both">Both (Customer + Supplier)</option>
                </select>
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.name') }} *</label>
                <input type="text" name="name" required class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.Company Name') }} *</label>
                <input type="text" name="company_name" required class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.VAT Number') }}</label>
                <input type="text" name="vat_number" class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.Email') }} *</label>
                <input type="email" name="email" required class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.Phone') }} *</label>
                <input type="text" name="phone" required class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.Address') }} *</label>
                <input type="text" name="address" required class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.City') }} *</label>
                <input type="text" name="city" required class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.State') }}</label>
                <input type="text" name="state" class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.Postal Code') }}</label>
                <input type="text" name="postal_code" class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.Country') }}</label>
                <input type="text" name="country" class="form-control">
              </div>

              <div class="col-md-4">
                <label>{{ trans('file.Image') }}</label>
                <input type="file" name="image" class="form-control">
              </div>

              <div class="col-md-12 mt-3">
                <button type="submit" class="btn btn-primary">{{ trans('file.submit') }}</button>
              </div>
            </div>
            {!! Form::close() !!}
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

@endsection

@push('scripts')
<script>
  $("ul#people").siblings('a').attr('aria-expanded','true');
  $("ul#people").addClass("show");
  $("ul#people #supplier-create-menu").addClass("active");
</script>
@endpush
