<ul id="side-main-menu" class="side-menu list-unstyled d-print-none">
    <!-- Dashboard -->
    <li><a href="{{ url('/dashboard') }}"> 
        <i class="dripicons-meter"></i><span>{{ __('file.dashboard') }}</span>
    </a></li>

    <!-- Sales Menu -->
    <li>
        <a href="#sale" aria-expanded="false" data-toggle="collapse">
            <i class="dripicons-cart"></i><span>{{ trans('file.Sale') }}</span>
        </a>
        <ul id="sale" class="collapse list-unstyled">
            <li id="sale-list-menu"><a href="{{ route('sales.index') }}">{{ trans('file.Sale List') }}</a></li>
            <li id="sale-create-menu"><a href="{{ route('sales.create') }}">{{ trans('file.Add Sale') }}</a></li>
        </ul>
    </li>

    <!-- People Menu -->
    <li>
        <a href="#people" aria-expanded="false" data-toggle="collapse">
            <i class="dripicons-user"></i><span>{{ trans('file.People') }}</span>
        </a>
        <ul id="people" class="collapse list-unstyled">
            <li id="user-list-menu"><a href="{{ route('user.index') }}">{{ trans('file.User List') }}</a></li>
            <li id="user-create-menu"><a href="{{ route('user.create') }}">{{ trans('file.Add User') }}</a></li>
            <li id="customer-list-menu"><a href="{{ route('customer.index') }}">{{ trans('file.Customer List') }}</a></li>
            <li id="customer-create-menu"><a href="{{ route('customer.create') }}">{{ trans('file.Add Customer') }}</a></li>
            <li id="biller-list-menu"><a href="{{ route('biller.index') }}">{{ trans('file.Biller List') }}</a></li>
            <li id="biller-create-menu"><a href="{{ route('biller.create') }}">{{ trans('file.Add Biller') }}</a></li>
            <li id="supplier-list-menu"><a href="{{ route('supplier.index') }}">{{ trans('file.Supplier List') }}</a></li>
            <li id="supplier-create-menu"><a href="{{ route('supplier.create') }}">{{ trans('file.Add Supplier') }}</a></li>
        </ul>
    </li>

    <!-- Reports Menu -->
    <li>
        <a href="#report" aria-expanded="false" data-toggle="collapse">
            <i class="dripicons-document-remove"></i><span>{{ trans('file.Reports') }}</span>
        </a>
        <ul id="report" class="collapse list-unstyled">
            <li id="sale-report-menu">
                @include('backend.report._report_form', [
                    'routeName' => 'report.sale',
                    'linkId' => 'sale-report-link',
                    'text' => trans('file.Sale Report'),
                    'params' => ['start_date' => date('Y-m') . '-01', 'end_date' => date('Y-m-d'), 'warehouse_id' => 0],
                ])
            </li>
            <li id="currency-report-menu">
                @include('backend.report._report_form', [
                    'routeName' => 'report.currency',
                    'linkId' => 'currency-report-link',
                    'text' => 'Currency Wise Report',
                    'params' => [],
                ])
            </li>
            <li id="customer-report-menu">
                @include('backend.report._report_form', [
                    'routeName' => 'report.customer',
                    'linkId' => 'customer-report-link',
                    'text' => 'Customer Wise Report',
                    'params' => [],
                ])
            </li>
            <li id="due-report-menu">
                @include('backend.report._report_form', [
                    'routeName' => 'report.due',
                    'linkId' => 'due-report-link',
                    'text' => 'Due Payments Report',
                    'params' => [],
                ])
            </li>
        </ul>
    </li>

    <!-- Settings Menu -->
    <li>
        <a href="#setting" aria-expanded="false" data-toggle="collapse">
            <i class="dripicons-gear"></i><span>{{ trans('file.settings') }}</span>
        </a>
        <ul id="setting" class="collapse list-unstyled">
            <li id="customer-group-menu"><a href="{{ route('customer_group.index') }}">{{ trans('file.Customer Group') }}</a></li>
            <li id="currency-menu"><a href="{{ route('currency.index') }}">{{ trans('file.Currency') }}</a></li>
        </ul>
    </li>
</ul>
