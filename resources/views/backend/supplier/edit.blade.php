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
            <h4>Update Party</h4>
          </div>
          <div class="card-body">
            {!! Form::open(['route' => ['supplier.update', $party->id], 'method' => 'put', 'files' => true]) !!}
            <div class="row">

              {{-- Party Type --}}
              <div class="col-md-6">
                <label>Party Type*</label>
                <select name="type" required class="form-control selectpicker">
                  <option value="customer" {{ $party->type == 'customer' ? 'selected' : '' }}>Customer</option>
                  <option value="supplier" {{ $party->type == 'supplier' ? 'selected' : '' }}>Supplier</option>
                  <option value="both" {{ $party->type == 'both' ? 'selected' : '' }}>Both (Customer + Supplier)</option>
                </select>
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.name') }} *</label>
                <input type="text" name="name" value="{{ $party->name }}" required class="form-control">
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.Company Name') }} *</label>
                <input type="text" name="company_name" value="{{ $party->company_name }}" required class="form-control">
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.VAT Number') }}</label>
                <input type="text" name="vat_number" value="{{ $party->vat_number }}" class="form-control">
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.Email') }} *</label>
                <input type="email" name="email" value="{{ $party->email }}" required class="form-control">
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.Phone') }} *</label>
                <input type="text" name="phone" value="{{ $party->phone }}" required class="form-control">
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.Address') }} *</label>
                <input type="text" name="address" value="{{ $party->address }}" required class="form-control">
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.City') }} *</label>
                <input type="text" name="city" value="{{ $party->city }}" required class="form-control">
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.State') }}</label>
                <input type="text" name="state" value="{{ $party->state }}" class="form-control">
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.Postal Code') }}</label>
                <input type="text" name="postal_code" value="{{ $party->postal_code }}" class="form-control">
              </div>

              <div class="col-md-6">
                <label>{{ trans('file.Country') }}</label>
                <input type="text" name="country" value="{{ $party->country }}" class="form-control">
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
  $("ul#people #supplier-list-menu").addClass("active");
</script>
@endpush
