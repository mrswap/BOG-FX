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
            @if (
                $category_permission_active ||
                    $index_permission_active ||
                    $print_barcode_active ||
                    $stock_count_active ||
                    $adjustment_active)
                <li><a href="#product" aria-expanded="false" data-toggle="collapse"> <i
                            class="dripicons-list"></i><span>{{ __('file.product') }}</span><span></a>
                    <ul id="product" class="collapse list-unstyled ">
                        @if ($category_permission_active)
                            <li id="category-menu"><a href="{{ route('category.index') }}">{{ __('file.category') }}</a>
                            </li>
                        @endif
                        @if ($index_permission_active)
                            <li id="product-list-menu"><a
                                    href="{{ route('products.index') }}">{{ __('file.product_list') }}</a></li>
                            <?php
                            $add_permission_active = $role_has_permissions_list->where('name', 'products-add')->first();
                            ?>
                            @if ($add_permission_active)
                                <li id="product-create-menu"><a
                                        href="{{ route('products.create') }}">{{ __('file.add_product') }}</a></li>
                            @endif
                        @endif
                    </ul>
                </li>
            @endif
            <?php
            $index_permission_active = $role_has_permissions_list->where('name', 'purchases-index')->first();
            ?>
            @if ($index_permission_active)
                <li><a href="#purchase" aria-expanded="false" data-toggle="collapse"> <i
                            class="dripicons-card"></i><span>{{ trans('file.Purchase') }}</span></a>
                    <ul id="purchase" class="collapse list-unstyled ">
                        <li id="purchase-list-menu"><a
                                href="{{ route('purchases.index') }}">{{ trans('file.Purchase List') }}</a></li>
                        <?php
                        $add_permission_active = $role_has_permissions_list->where('name', 'purchases-add')->first();
                        ?>
                        @if ($add_permission_active)
                            <li id="purchase-create-menu"><a
                                    href="{{ route('purchases.create') }}">{{ trans('file.Add Purchase') }}</a></li>
                        @endif
                    </ul>
                </li>
            @endif
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

            <?php
            
            $profit_loss_active = $role_has_permissions_list->where('name', 'profit-loss')->first();
            
            $best_seller_active = $role_has_permissions_list->where('name', 'best-seller')->first();
            
            $warehouse_report_active = $role_has_permissions_list->where('name', 'warehouse-report')->first();
            
            $warehouse_stock_report_active = $role_has_permissions_list->where('name', 'warehouse-stock-report')->first();
            
            $product_report_active = $role_has_permissions_list->where('name', 'product-report')->first();
            
            $daily_sale_active = $role_has_permissions_list->where('name', 'daily-sale')->first();
            
            $monthly_sale_active = $role_has_permissions_list->where('name', 'monthly-sale')->first();
            
            $daily_purchase_active = $role_has_permissions_list->where('name', 'daily-purchase')->first();
            
            $monthly_purchase_active = $role_has_permissions_list->where('name', 'monthly-purchase')->first();
            
            $purchase_report_active = $role_has_permissions_list->where('name', 'purchase-report')->first();
            
            $sale_report_active = $role_has_permissions_list->where('name', 'sale-report')->first();
            
            $sale_report_chart_active = $role_has_permissions_list->where('name', 'sale-report-chart')->first();
            
            $payment_report_active = $role_has_permissions_list->where('name', 'payment-report')->first();
            
            $product_expiry_report_active = $role_has_permissions_list->where('name', 'product-expiry-report')->first();
            
            $product_qty_alert_active = $role_has_permissions_list->where('name', 'product-qty-alert')->first();
            
            $dso_report_active = $role_has_permissions_list->where('name', 'dso-report')->first();
            
            $user_report_active = $role_has_permissions_list->where('name', 'user-report')->first();
            
            $biller_report_active = $role_has_permissions_list->where('name', 'biller-report')->first();
            
            $customer_report_active = $role_has_permissions_list->where('name', 'customer-report')->first();
            
            $supplier_report_active = $role_has_permissions_list->where('name', 'supplier-report')->first();
            
            $due_report_active = $role_has_permissions_list->where('name', 'due-report')->first();
            
            $supplier_due_report_active = $role_has_permissions_list->where('name', 'supplier-due-report')->first();
            
            ?>
            @if (
                $profit_loss_active ||
                    $best_seller_active ||
                    $warehouse_report_active ||
                    $warehouse_stock_report_active ||
                    $product_report_active ||
                    $daily_sale_active ||
                    $monthly_sale_active ||
                    $daily_purchase_active ||
                    $monthly_purchase_active ||
                    $purchase_report_active ||
                    $sale_report_active ||
                    $sale_report_chart_active ||
                    $payment_report_active ||
                    $product_expiry_report_active ||
                    $product_qty_alert_active ||
                    $dso_report_active ||
                    $user_report_active ||
                    $biller_report_active ||
                    $customer_report_active ||
                    $supplier_report_active ||
                    $due_report_active ||
                    $supplier_due_report_active)
                <li><a href="#report" aria-expanded="false" data-toggle="collapse"> <i
                            class="dripicons-document-remove"></i><span>{{ trans('file.Reports') }}</span></a>
                    <ul id="report" class="collapse list-unstyled ">
                        @if ($profit_loss_active)
                            <li id="profit-loss-report-menu">
                                {!! Form::open(['route' => 'report.profitLoss', 'method' => 'post', 'id' => 'profitLoss-report-form']) !!}
                                <input type="hidden" name="start_date" value="{{ date('Y-m') . '-' . '01' }}" />
                                <input type="hidden" name="end_date" value="{{ date('Y-m-d') }}" />
                                <a id="profitLoss-link" href="">{{ trans('file.Summary Report') }}</a>
                                {!! Form::close() !!}
                            </li>
                        @endif
                        @if ($product_report_active)
                            <li id="product-report-menu">
                                {!! Form::open(['route' => 'report.product', 'method' => 'get', 'id' => 'product-report-form']) !!}
                                <input type="hidden" name="start_date" value="{{ date('Y-m') . '-' . '01' }}" />
                                <input type="hidden" name="end_date" value="{{ date('Y-m-d') }}" />
                                <input type="hidden" name="warehouse_id" value="0" />
                                <a id="report-link" href="">{{ trans('file.Product Report') }}</a>
                                {!! Form::close() !!}
                            </li>
                        @endif
                        @if ($daily_sale_active)
                            <li id="daily-sale-report-menu">
                                <a
                                    href="{{ url('report/daily_sale/' . date('Y') . '/' . date('m')) }}">{{ trans('file.Daily Sale') }}</a>
                            </li>
                        @endif
                        @if ($monthly_sale_active)
                            <li id="monthly-sale-report-menu">
                                <a
                                    href="{{ url('report/monthly_sale/' . date('Y')) }}">{{ trans('file.Monthly Sale') }}</a>
                            </li>
                        @endif
                        @if ($daily_purchase_active)
                            <li id="daily-purchase-report-menu">
                                <a
                                    href="{{ url('report/daily_purchase/' . date('Y') . '/' . date('m')) }}">{{ trans('file.Daily Purchase') }}</a>
                            </li>
                        @endif
                        @if ($monthly_purchase_active)
                            <li id="monthly-purchase-report-menu">
                                <a
                                    href="{{ url('report/monthly_purchase/' . date('Y')) }}">{{ trans('file.Monthly Purchase') }}</a>
                            </li>
                        @endif
                        @if ($sale_report_active)
                            <li id="sale-report-menu">
                                {!! Form::open(['route' => 'report.sale', 'method' => 'post', 'id' => 'sale-report-form']) !!}
                                <input type="hidden" name="start_date" value="{{ date('Y-m') . '-' . '01' }}" />
                                <input type="hidden" name="end_date" value="{{ date('Y-m-d') }}" />
                                <input type="hidden" name="warehouse_id" value="0" />
                                <a id="sale-report-link" href="">{{ trans('file.Sale Report') }}</a>
                                {!! Form::close() !!}
                            </li>
                        @endif
                        @if ($payment_report_active)
                            <li id="payment-report-menu">
                                {!! Form::open(['route' => 'report.paymentByDate', 'method' => 'post', 'id' => 'payment-report-form']) !!}
                                <input type="hidden" name="start_date" value="{{ date('Y-m') . '-' . '01' }}" />
                                <input type="hidden" name="end_date" value="{{ date('Y-m-d') }}" />
                                <a id="payment-report-link" href="">{{ trans('file.Payment Report') }}</a>
                                {!! Form::close() !!}
                            </li>
                        @endif
                        @if ($purchase_report_active)
                            <li id="purchase-report-menu">
                                {!! Form::open(['route' => 'report.purchase', 'method' => 'post', 'id' => 'purchase-report-form']) !!}
                                <input type="hidden" name="start_date" value="{{ date('Y-m') . '-' . '01' }}" />
                                <input type="hidden" name="end_date" value="{{ date('Y-m-d') }}" />
                                <input type="hidden" name="warehouse_id" value="0" />
                                <a id="purchase-report-link" href="">{{ trans('file.Purchase Report') }}</a>
                                {!! Form::close() !!}
                            </li>
                        @endif
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
                    @if ($role->id <= 2)
                        <li id="role-menu"><a
                                href="{{ route('role.index') }}">{{ trans('file.Role Permission') }}</a>
                        </li>
                        @if ($custom_field_permission_active)
                            <li id="custom-field-list-menu"><a
                                    href="{{ route('custom-fields.index') }}">{{ trans('file.Custom Field List') }}</a>
                            </li>
                        @endif
                    @endif
                    @if ($customer_group_permission_active)
                        <li id="customer-group-menu"><a
                                href="{{ route('customer_group.index') }}">{{ trans('file.Customer Group') }}</a>
                        </li>
                    @endif
                    @if ($brand_permission_active)
                        <li id="brand-menu"><a href="{{ route('brand.index') }}">{{ trans('file.Brand') }}</a></li>
                    @endif
                    @if ($unit_permission_active)
                        <li id="unit-menu"><a href="{{ route('unit.index') }}">{{ trans('file.Unit') }}</a></li>
                    @endif
                    @if ($currency_permission_active)
                        <li id="currency-menu"><a
                                href="{{ route('currency.index') }}">{{ trans('file.Currency') }}</a></li>
                    @endif
                    @if ($tax_permission_active)
                        <li id="tax-menu"><a href="{{ route('tax.index') }}">{{ trans('file.Tax') }}</a></li>
                    @endif
                    <li id="user-menu"><a
                            href="{{ route('user.profile', ['id' => Auth::id()]) }}">{{ trans('file.User Profile') }}</a>
                    </li>
                    @if ($general_setting_permission_active)
                        <li id="general-setting-menu"><a
                                href="{{ route('setting.general') }}">{{ trans('file.General Setting') }}</a></li>
                    @endif
                    @if ($mail_setting_permission_active)
                        <li id="mail-setting-menu"><a
                                href="{{ route('setting.mail') }}">{{ trans('file.Mail Setting') }}</a></li>
                    @endif
                    <li id="languages"><a href="{{ url('languages/') }}"> {{ trans('file.Languages') }}</a></li>
                </ul>
            </li>
        </ul>
