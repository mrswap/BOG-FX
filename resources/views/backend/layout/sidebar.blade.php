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
            <li id="sale-list-menu"><a href="{{ route('sales.index') }}">Remitance List</a></li>
            <li id="sale-create-menu"><a href="{{ route('sales.create') }}">Create Remitance </a></li>
        </ul>
    </li>

    <!-- People Menu -->
    <li>
        <a href="#people" aria-expanded="false" data-toggle="collapse">
            <i class="dripicons-user"></i><span>{{ trans('file.People') }}</span>
        </a>
        <ul id="people" class="collapse list-unstyled">
            <li id="supplier-list-menu"><a href="{{ route('supplier.index') }}">Party List</a></li>
            <li id="supplier-create-menu"><a href="{{ route('supplier.create') }}">Add Party</a></li>
        </ul>
    </li>

    <!-- Reports Menu -->
    <!-- Forex Reports Menu -->
    <li>
        <a href="#forexReportMenu" aria-expanded="false" data-toggle="collapse">
            <i class="dripicons-document"></i>
            <span>Forex Reports</span>
        </a>

        <ul id="forexReportMenu" class="collapse list-unstyled">

            <li>
                <a href="{{ route('forex.report', 'invoice') }}">
                    <i class="dripicons-document-edit"></i> Invoice Wise Report
                </a>
            </li>

            <li>
                <a href="{{ route('forex.report', 'party') }}">
                    <i class="dripicons-user"></i> Party Wise Report
                </a>
            </li>

            <li>
                <a href="{{ route('forex.report', 'base') }}">
                    <i class="dripicons-gear"></i> Base Currency Wise Report
                </a>
            </li>

            <li>
                <a href="{{ route('forex.report', 'local') }}">
                    <i class="dripicons-flag"></i> Local Currency Wise Report
                </a>
            </li>

            <li>
                <a href="{{ route('forex.report', 'realised') }}">
                    <i class="dripicons-checkmark"></i> Realised Gain/Loss
                </a>
            </li>

            <li>
                <a href="{{ route('forex.report', 'unrealised') }}">
                    <i class="dripicons-warning"></i> Unrealised Gain/Loss
                </a>
            </li>

        </ul>
    </li>

    <!-- Settings Menu -->
    <li>
        <a href="#setting" aria-expanded="false" data-toggle="collapse">
            <i class="dripicons-gear"></i><span>{{ trans('file.settings') }}</span>
        </a>
        <ul id="setting" class="collapse list-unstyled">
            <li id="currency-menu"><a href="{{ route('currency.index') }}">{{ trans('file.Currency') }}</a></li>
        </ul>
    </li>
</ul>
