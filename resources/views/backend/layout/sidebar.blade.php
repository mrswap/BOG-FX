        <ul id="side-main-menu" class="side-menu list-unstyled d-print-none">
            <li><a href="{{ url('/dashboard') }}"> <i
                        class="dripicons-meter"></i><span>{{ __('file.dashboard') }}</span></a></li>
            <?php
            
            $index_permission_active = $role_has_permissions_list->where('name', 'products-index')->first();
            
            $category_permission_active = $role_has_permissions_list->where('name', 'category')->first();
            
            $print_barcode_active = $role_has_permissions_list->where('name', 'print_barcode')->first();
            
            $stock_count_active = $role_has_permissions_list->where('name', 'stock_count')->first();
            
            $adjustment_active = $role_has_permissions_list->where('name', 'adjustment')->first();
            ?>
            <?php
            $sale_index_permission_active = $role_has_permissions_list->where('name', 'sales-index')->first();
            
            $packing_slip_challan_active = $role_has_permissions_list->where('name', 'packing_slip_challan')->first();
            
            $gift_card_permission_active = $role_has_permissions_list->where('name', 'gift_card')->first();
            
            $coupon_permission_active = $role_has_permissions_list->where('name', 'coupon')->first();
            
            $delivery_permission_active = $role_has_permissions_list->where('name', 'delivery')->first();
            
            $sale_add_permission_active = $role_has_permissions_list->where('name', 'sales-add')->first();
            ?>
            @if (
                $sale_index_permission_active ||
                    $packing_slip_challan_active ||
                    $gift_card_permission_active ||
                    $coupon_permission_active ||
                    $delivery_permission_active)
                <li><a href="#sale" aria-expanded="false" data-toggle="collapse"> <i
                            class="dripicons-cart"></i><span>{{ trans('file.Sale') }}</span></a>
                    <ul id="sale" class="collapse list-unstyled ">
                        @if ($sale_index_permission_active)
                            <li id="sale-list-menu"><a
                                    href="{{ route('sales.index') }}">{{ trans('file.Sale List') }}</a></li>
                        @endif
                        @if ($sale_add_permission_active)
                            <li id="sale-create-menu"><a
                                    href="{{ route('sales.create') }}">{{ trans('file.Add Sale') }}</a></li>
                        @endif
                    </ul>
                </li>
            @endif


            <?php
            
            $user_index_permission_active = $role_has_permissions_list->where('name', 'users-index')->first();
            
            $customer_index_permission_active = $role_has_permissions_list->where('name', 'customers-index')->first();
            
            $biller_index_permission_active = $role_has_permissions_list->where('name', 'billers-index')->first();
            
            $supplier_index_permission_active = $role_has_permissions_list->where('name', 'suppliers-index')->first();
            
            ?>
            @if (
                $user_index_permission_active ||
                    $customer_index_permission_active ||
                    $biller_index_permission_active ||
                    $supplier_index_permission_active)
                <li><a href="#people" aria-expanded="false" data-toggle="collapse"> <i
                            class="dripicons-user"></i><span>{{ trans('file.People') }}</span></a>
                    <ul id="people" class="collapse list-unstyled ">

                        @if ($user_index_permission_active)
                            <li id="user-list-menu"><a
                                    href="{{ route('user.index') }}">{{ trans('file.User List') }}</a></li>
                            <?php
                            $user_add_permission_active = $role_has_permissions_list->where('name', 'users-add')->first();
                            ?>
                            @if ($user_add_permission_active)
                                <li id="user-create-menu"><a
                                        href="{{ route('user.create') }}">{{ trans('file.Add User') }}</a></li>
                            @endif
                        @endif

                        @if ($customer_index_permission_active)
                            <li id="customer-list-menu"><a
                                    href="{{ route('customer.index') }}">{{ trans('file.Customer List') }}</a></li>
                            <?php
                            $customer_add_permission_active = $role_has_permissions_list->where('name', 'customers-add')->first();
                            ?>
                            @if ($customer_add_permission_active)
                                <li id="customer-create-menu"><a
                                        href="{{ route('customer.create') }}">{{ trans('file.Add Customer') }}</a>
                                </li>
                            @endif
                        @endif

                        @if ($biller_index_permission_active)
                            <li id="biller-list-menu"><a
                                    href="{{ route('biller.index') }}">{{ trans('file.Biller List') }}</a></li>
                            <?php
                            $biller_add_permission_active = $role_has_permissions_list->where('name', 'billers-add')->first();
                            ?>
                            @if ($biller_add_permission_active)
                                <li id="biller-create-menu"><a
                                        href="{{ route('biller.create') }}">{{ trans('file.Add Biller') }}</a></li>
                            @endif
                        @endif

                        @if ($supplier_index_permission_active)
                            <li id="supplier-list-menu"><a
                                    href="{{ route('supplier.index') }}">{{ trans('file.Supplier List') }}</a></li>
                            <?php
                            $supplier_add_permission_active = $role_has_permissions_list->where('name', 'suppliers-add')->first();
                            ?>
                            @if ($supplier_add_permission_active)
                                <li id="supplier-create-menu"><a
                                        href="{{ route('supplier.create') }}">{{ trans('file.Add Supplier') }}</a>
                                </li>
                            @endif
                        @endif
                    </ul>
                </li>
            @endif




            <!-- reports -->
            @php
                $sale_report_active = $role_has_permissions_list->where('name', 'sale-report')->first();
            @endphp

            @if ($sale_report_active)
                <li>
                    <a href="#report" aria-expanded="false" data-toggle="collapse">
                        <i class="dripicons-document-remove"></i><span>{{ trans('file.Reports') }}</span>
                    </a>

                    <ul id="report" class="collapse list-unstyled">

                        {{-- Sale Report --}}
                        <li id="sale-report-menu">
                            @include('backend.report._report_form', [
                                'routeName' => 'report.sale',
                                'linkId' => 'sale-report-link',
                                'text' => trans('file.Sale Report'),
                                'params' => [
                                    'start_date' => date('Y-m') . '-01',
                                    'end_date' => date('Y-m-d'),
                                    'warehouse_id' => 0,
                                ],
                            ])
                        </li>

                        {{-- Currency Wise Report --}}
                        <li id="currency-report-menu">
                            @include('backend.report._report_form', [
                                'routeName' => 'report.currency',
                                'linkId' => 'currency-report-link',
                                'text' => 'Currency Wise Report',
                                'params' => [],
                            ])
                        </li>

                        {{-- Customer Wise Report --}}
                        <li id="customer-report-menu">
                            @include('backend.report._report_form', [
                                'routeName' => 'report.customer',
                                'linkId' => 'customer-report-link',
                                'text' => 'Customer Wise Report',
                                'params' => [],
                            ])
                        </li>

                        {{-- Due Payments --}}
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
            @endif











            <li><a href="#setting" aria-expanded="false" data-toggle="collapse"> <i
                        class="dripicons-gear"></i><span>{{ trans('file.settings') }}</span></a>
                <ul id="setting" class="collapse list-unstyled ">
                    <?php
                    $all_notification_permission_active = $role_has_permissions_list->where('name', 'all_notification')->first();
                    
                    $send_notification_permission_active = $role_has_permissions_list->where('name', 'send_notification')->first();
                    
                    $warehouse_permission_active = $role_has_permissions_list->where('name', 'warehouse')->first();
                    
                    $customer_group_permission_active = $role_has_permissions_list->where('name', 'customer_group')->first();
                    
                    $brand_permission_active = $role_has_permissions_list->where('name', 'brand')->first();
                    
                    $unit_permission_active = $role_has_permissions_list->where('name', 'unit')->first();
                    
                    $currency_permission_active = $role_has_permissions_list->where('name', 'currency')->first();
                    
                    $tax_permission_active = $role_has_permissions_list->where('name', 'tax')->first();
                    
                    $general_setting_permission_active = $role_has_permissions_list->where('name', 'general_setting')->first();
                    
                    $backup_database_permission_active = $role_has_permissions_list->where('name', 'backup_database')->first();
                    
                    $mail_setting_permission_active = $role_has_permissions_list->where('name', 'mail_setting')->first();
                    
                    $sms_setting_permission_active = $role_has_permissions_list->where('name', 'sms_setting')->first();
                    
                    $create_sms_permission_active = $role_has_permissions_list->where('name', 'create_sms')->first();
                    
                    $pos_setting_permission_active = $role_has_permissions_list->where('name', 'pos_setting')->first();
                    
                    $hrm_setting_permission_active = $role_has_permissions_list->where('name', 'hrm_setting')->first();
                    
                    $reward_point_setting_permission_active = $role_has_permissions_list->where('name', 'reward_point_setting')->first();
                    
                    $discount_plan_permission_active = $role_has_permissions_list->where('name', 'discount_plan')->first();
                    
                    $discount_permission_active = $role_has_permissions_list->where('name', 'discount')->first();
                    
                    $custom_field_permission_active = $role_has_permissions_list->where('name', 'custom_field')->first();
                    ?>

                    @if ($customer_group_permission_active)
                        <li id="customer-group-menu"><a
                                href="{{ route('customer_group.index') }}">{{ trans('file.Customer Group') }}</a>
                        </li>
                    @endif

                    @if ($currency_permission_active)
                        <li id="currency-menu"><a
                                href="{{ route('currency.index') }}">{{ trans('file.Currency') }}</a></li>
                    @endif

                </ul>
            </li>
        </ul>
