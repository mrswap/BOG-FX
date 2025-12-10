<ul id="side-main-menu" class="side-menu list-unstyled d-print-none">
    <!-- Dashboard -->
    <!-- Dashboard -->
    <li><a href="{{ url('/dashboard') }}">
            <i class="dripicons-meter"></i><span>{{ __('file.dashboard') }}</span>
        </a></li>

    <!-- Sales Menu -->
    <li>
        <a href="#sale" aria-expanded="false" data-toggle="collapse">
            <i class="dripicons-cart"></i><span>Fx <small>Inward-OutWard</small></span>
        </a>
        <ul id="sale" class="collapse list-unstyled">

            <li id="currency-menu"><a href="{{ route('currency.index') }}">{{ trans('file.Currency') }}</a></li>

            <li id="supplier-list-menu"><a href="{{ route('supplier.index') }}">Party List</a></li>
            <li id="supplier-create-menu"><a href="{{ route('supplier.create') }}">Add Party</a></li>

            
            <li id="sale-create-menu"><a href="{{ route('sales.create') }}">Create Remitance </a></li>
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

            <li><a href="{{ route('sales.index') }}">All Remitance List</a></li>
             <li>
                <a href="{{ route('forex.txn.report.party') }}">
                    <i class="dripicons-user"></i> Party Wise Report
                </a>
            </li>

            <li>
                <a href="{{ route('forex.txn.report.currency') }}">
                    <i class="dripicons-gear"></i>Currency Wise Report
                </a>
            </li>


            

            <li>
                <a href="{{ route('forex.txn.report.invoice') }}">
                    <i class="dripicons-gear"></i>Invoice Wise Report
                </a>
            </li>

        </ul>
    </li>

</ul>
