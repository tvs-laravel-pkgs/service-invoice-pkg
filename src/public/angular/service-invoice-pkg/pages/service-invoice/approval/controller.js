app.component('serviceInvoiceApprovalList', {
    templateUrl: service_invoice_approval_list_template_url,
    controller: function($http, $location, HelperService, $scope, $routeParams, $rootScope, $element) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        $http.get(
            approval_type_validation_url
        ).then(function(response) {
            if (!response.data.success) {
                new Noty({
                    type: 'error',
                    layout: 'topRight',
                    text: response.data.error,
                }).show();
                $location.path('/service-invoice-pkg/service-invoice/list')
                $scope.$apply()
            }
            self.approval_type_id = response.data.approval_level.current_status_id;
            $rootScope.loading = false;
        });
        $http.get(
            get_cn_dn_approval_filter_url
        ).then(function(response) {
            self.extras = response.data.extras;
            $rootScope.loading = false;
            //console.log(self.extras);
        });
        var dataTable;
        setTimeout(function() {
            var table_scroll;
            table_scroll = $('.page-main-content').height() - 37;
            dataTable = $('#cn-dn-approval-table').DataTable({
                "dom": cndn_dom_structure,
                "language": {
                    // "search": "",
                    // "searchPlaceholder": "Search",
                    "lengthMenu": "Rows _MENU_",
                    "paginate": {
                        "next": '<i class="icon ion-ios-arrow-forward"></i>',
                        "previous": '<i class="icon ion-ios-arrow-back"></i>'
                    },
                },
                scrollX: true,
                scrollY: table_scroll + "px",
                scrollCollapse: true,
                stateSave: true,
                stateSaveCallback: function(settings, data) {
                    localStorage.setItem('SIDataTables_' + settings.sInstance, JSON.stringify(data));
                },
                stateLoadCallback: function(settings) {
                    var state_save_val = JSON.parse(localStorage.getItem('SIDataTables_' + settings.sInstance));
                    if (state_save_val) {
                        $('#search').val(state_save_val.search.search);
                    }
                    return JSON.parse(localStorage.getItem('SIDataTables_' + settings.sInstance));
                },
                processing: true,
                serverSide: true,
                paging: true,
                searching: true,
                ordering: false,

                ajax: {
                    url: laravel_routes['getServiceInvoiceApprovalList'],
                    type: "GET",
                    dataType: "json",
                    data: function(d) {
                        d.approval_status_id = self.approval_type_id;
                        d.invoice_number = $('#invoice_number').val();
                        d.invoice_date = $('#invoice_date').val();
                        d.type_id = $('#type_id').val();
                        d.branch_id = $('#branch_id').val();
                        d.sbu_id = $('#sbu_id').val();
                        d.category_id = $('#category_id').val();
                        d.sub_category_id = $('#sub_category_id').val();
                        d.customer_id = $('#customer_id').val();
                        d.status_id = $('#status_id').val();
                    }
                },

                columns: [
                    { data: 'child_checkbox', searchable: false },
                    { data: 'action', searchable: false, class: 'action' },
                    { data: 'document_date', searchable: false },
                    { data: 'number', name: 'service_invoices.number', searchable: true },
                    { data: 'type_name', name: 'configs.name', searchable: true },
                    { data: 'branch', name: 'outlets.code', searchable: true },
                    { data: 'sbu', name: 'sbus.name', searchable: true },
                    { data: 'category', name: 'service_item_categories.name', searchable: true },
                    { data: 'sub_category', name: 'service_item_sub_categories.name', searchable: true },
                    { data: 'customer_code', name: 'customers.code', searchable: true },
                    { data: 'customer_name', name: 'customers.name', searchable: true },
                    { data: 'invoice_amount', searchable: false, class: 'text-right' },
                    { data: 'status', name: 'approval_type_statuses.status', searchable: false },
                ],
                "initComplete": function(settings, json) {
                    $('.dataTables_length select').select2();
                },
                rowCallback: function(row, data) {
                    $(row).addClass('highlight-row');
                },
                infoCallback: function(settings, start, end, max, total, pre) {
                    $('#table_info').html(total)
                    $('.foot_info').html('Showing ' + start + ' to ' + end + ' of ' + max + ' entries')
                },
            });
        }, 1000);
        $('.modal').bind('click', function(event) {
            if ($('.md-select-menu-container').hasClass('md-active')) {
                $mdSelect.hide();
            }
        });
        $('.refresh_table').on("click", function() {
            $('#cn-dn-approval-table').DataTable().ajax.reload();
        });
        $('#invoice_number').keyup(function() {
            setTimeout(function() {
                dataTable.draw();
            }, 900);
        });
        $('body').on('click', '.applyBtn', function() { //alert('sd');
            setTimeout(function() {
                dataTable.draw();
            }, 900);
        });
        $('body').on('click', '.cancelBtn', function() { //alert('sd');
            setTimeout(function() {
                dataTable.draw();
            }, 900);
        });

        $scope.onSelectedType = function(selected_type) {
            setTimeout(function() {
                $('#type_id').val(selected_type);
                dataTable.draw();
            }, 900);
        }
        $scope.getSelectedSbu = function(selected_sbu_id) {
            setTimeout(function() {
                $('#sbu_id').val(selected_sbu_id);
                dataTable.draw();
            }, 900);
        }
        $scope.getSubCategory = function(selected_sub_category_id) {
            setTimeout(function() {
                $('#sub_category_id').val(selected_sub_category_id);
                dataTable.draw();
            }, 900);
        }
        $scope.getSelectedStatus = function(selected_status_id) {
            setTimeout(function() {
                $('#status_id').val(selected_status_id);
                dataTable.draw();
            }, 900);
        }
        $scope.reset_filter = function() {
            $('#invoice_number').val('');
            $('#invoice_date').val('');
            $('#type_id').val('');
            $('#branch_id').val('');
            $('#sbu_id').val('');
            $('#category_id').val('');
            $('#sub_category_id').val('');
            $('#customer_id').val('');
            $('#status_id').val('');
            dataTable.draw();
        }
        //GET SERVICE ITEM SUB CATEGORY BY CATEGORY
        $scope.getServiceItemSubCategory = function(category_id) {
            self.extras.sub_category_list = [];
            if (category_id == '') {
                $('#sub_category_id').val('');
            }
            $('#category_id').val(category_id);
            dataTable.draw();
            if (category_id) {
                $.ajax({
                        url: get_service_item_sub_category_url + '/' + category_id,
                        method: "GET",
                    })
                    .done(function(res) {
                        if (!res.success) {
                            new Noty({
                                type: 'error',
                                layout: 'topRight',
                                text: res.error
                            }).show();
                        } else {
                            self.extras.sub_category_list = res.sub_category_list;
                            $scope.$apply()
                        }
                    })
                    .fail(function(xhr) {
                        console.log(xhr);
                    });
            }
        }

        $(".search_clear").on("click", function() {
            $('#search').val('');
            $('#cn-dn-approval-table').DataTable().search('').draw();
        });

        $("#search").on('keyup', function() {
            dataTable
                .search(this.value)
                .draw();
        });
        
        $('#send_for_approval').on('click', function() { //alert('dsf');
            if ($('.service_invoice_checkbox:checked').length > 0) {
                var send_for_approval = []
                $('input[name="child_boxes"]:checked').each(function() {
                    send_for_approval.push(this.value);
                });
                // console.log(send_for_approval);
                $http.post(
                    laravel_routes['updateMultipleApproval'], {
                        send_for_approval: send_for_approval,
                    }
                ).then(function(response) {
                    if (response.data.success == true) {
                        custom_noty('success', response.data.message);
                        $('#cn-dn-approval-table').DataTable().ajax.reload();
                        $scope.$apply();
                    } else {
                        custom_noty('error', response.data.errors);
                    }
                });
            } else {
                custom_noty('error', 'Please Select Checkbox');
            }
        })

        $('#parent').on('click', function() {
            if (this.checked) {
                $('.service_invoice_checkbox').each(function() {
                    this.checked = true;
                });
            } else {
                $('.service_invoice_checkbox').each(function() {
                    this.checked = false;
                });
            }
        });
        $(document.body).on('click', '.service_invoice_checkbox', function() {
            if ($('.service_invoice_checkbox:checked').length == $('.service_invoice_checkbox').length) {
                $('#parent').prop('checked', true);
            } else {
                $('#parent').prop('checked', false);
            }
        });

        $('.align-left.daterange').daterangepicker({
            autoUpdateInput: false,
            "opens": "left",
            locale: {
                cancelLabel: 'Clear',
                format: "DD-MM-YYYY"
            }
        });

        $('.daterange').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('DD-MM-YYYY') + ' to ' + picker.endDate.format('DD-MM-YYYY'));
        });

        $('.daterange').on('cancel.daterangepicker', function(ev, picker) {
            $(this).val('');
        });
        //SEARCH BRANCH
        self.searchBranchFilter = function(query) {
            if (query) {
                return new Promise(function(resolve, reject) {
                    $http
                        .post(
                            search_branch_url, {
                                key: query,
                            }
                        )
                        .then(function(response) {
                            resolve(response.data);
                        });
                    //reject(response);
                });
            } else {
                return [];
            }
        }
        //GET BRANCH DETAILS
        self.getBranchDetails = function() {
            if (self.service_invoice.branch == null) {
                $('#branch_id').val('');
                dataTable.draw();
                return
            }
            $scope.getSbuByBranch(self.service_invoice.branch.id);
        }
        //GET SBU BY BRANCH
        $scope.getSbuByBranch = function(branch_id) {
            self.extras.sbu_list = [];
            $('#branch_id').val(branch_id);
            dataTable.draw();
            if (branch_id) {
                $.ajax({
                        url: get_sbu_url + '/' + branch_id,
                        method: "GET",
                    })
                    .done(function(res) {
                        if (!res.success) {
                            new Noty({
                                type: 'error',
                                layout: 'topRight',
                                text: res.error
                            }).show();
                        } else {
                            self.extras.sbu_list = res.sbu_list;
                            $scope.$apply()
                        }
                    })
                    .fail(function(xhr) {
                        console.log(xhr);
                    });
            }
        }
        //BRANCH CHANGED
        self.branchChanged = function() {
            self.service_invoice.sbu_id = '';
            self.extras.sbu_list = [];
        }
        //SEARCH CUSTOMER
        self.searchCustomer = function(query) {
            if (query) {
                return new Promise(function(resolve, reject) {
                    $http
                        .post(
                            search_customer_url, {
                                key: query,
                            }
                        )
                        .then(function(response) {
                            resolve(response.data);
                        });
                    //reject(response);
                });
            } else {
                return [];
            }
        }
        //GET CUSTOMER DETAILS
        $scope.getCustomerDetails = function(selected_customer_id) {
            $('#customer_id').val(selected_customer_id);
            dataTable.draw();
        }
        window.onpopstate = function (e) { window.history.forward(1); }
        $rootScope.loading = false;
    }
});

//------------------------------------------------------------------------------------------------------------------------
//------------------------------------------------------------------------------------------------------------------------
app.component('serviceInvoiceApprovalView', {
    templateUrl: service_invoice_approval_view_template_url,
    controller: function($http, $location, HelperService, $routeParams, $rootScope, $scope, $timeout, $mdSelect, $window) {
        if ($routeParams.type_id == 1060 || $routeParams.type_id == 1061) {} else {
            $location.path('/page-not-found')
            return;
        }
        $form_data_url = cn_dn_approval_view_data_url + '/' + $routeParams.approval_type_id + '/' + $routeParams.type_id + '/' + $routeParams.id;
        //alert($form_data_url);
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;
        self.type_id = $routeParams.type_id;
        self.approval_type_id = $routeParams.approval_type_id;
        self.enable_service_item_md_change = true;
        self.ref_attachements_url_link = ref_attachements_url;
        if (self.type_id == 1060) {
            self.minus_value = '-';
        } else if (self.type_id == 1061) {
            self.minus_value = '';
        }
        $http.get(
            $form_data_url
        ).then(function(response) {
            if (!response.data.success) {
                new Noty({
                    type: 'error',
                    layout: 'topRight',
                    text: response.data.error,
                }).show();
                $location.path('/service-invoice-pkg/cn-dn/approval/approval-level/' + $routeParams.approval_type_id + '/list/')
                $scope.$apply()
            }
            // self.list_url = cn_dn_approval_list_url;
            self.service_invoice = response.data.service_invoice;
            self.customer = {};
            self.extras = response.data.extras;
            self.action = response.data.action;
            self.service_invoice_status = response.data.service_invoice_status;
            // console.log(self.service_invoice);
            if (self.action == 'View') {
                $timeout(function() {
                    $scope.serviceInvoiceItemCalc();
                }, 1500);
            }
            $rootScope.loading = false;
        });

        /* Tab Funtion */
        $('.btn-nxt').on("click", function() {
            $('.cndn-tabs li.active').next().children('a').trigger("click");
            tabPaneFooter();
        });
        $('.btn-prev').on("click", function() {
            $('.cndn-tabs li.active').prev().children('a').trigger("click");
            tabPaneFooter();
        });

        //PERCENTAGE CALC
        $scope.percentage = function(num, per) {
            return (num / 100) * per;
        }

        //PARSEINT
        self.parseInt = function(num) {
            return parseInt(num);
        }

        //EDIT SERVICE INVOICE ITEM
        $scope.editServiceItem = function(service_invoice_item_id, description, qty, rate, index) {
            if (service_invoice_item_id) {
                self.enable_service_item_md_change = false;
                self.add_service_action = false;
                self.action_title = 'View';
                self.update_item_key = index;
                $http.post(
                    get_service_item_info_url, {
                        service_item_id: service_invoice_item_id,
                        field_groups: self.service_invoice.service_invoice_items[index].field_groups,
                        btn_action: 'edit',
                        branch_id: self.service_invoice.branch.id,
                        customer_id: self.service_invoice.customer.id,
                    }
                ).then(function(response) {
                    if (response.data.success) {
                        self.service_item_detail = response.data.service_item;
                        self.service_item = response.data.service_item;
                        self.description = description;
                        self.qty = parseInt(qty);
                        self.rate = rate;

                        //AMOUNT CALCULATION
                        $scope.totalAmountCalc();

                        //MODAL OPEN
                        $('#modal-cn-addnew').modal('toggle');
                    } else {
                        custom_noty('error', response.data.error);
                    }
                });
            }
        }

        //ITEM TO INVOICE TOTAL AMOUNT CALC
        $scope.totalAmountCalc = function() {
            self.sub_total = 0;
            self.total = 0;
            self.gst_total = 0;
            if (self.qty && self.rate) {
                self.sub_total = self.qty * self.rate;
                if (self.service_item_detail.tax_code != null) {
                    if (self.service_item_detail.tax_code.taxes.length > 0) {
                        $(self.service_item_detail.tax_code.taxes).each(function(key, tax) {
                            tax.pivot.amount = $scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2);
                            self.gst_total += parseFloat($scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2));
                        });
                    }
                }
                self.total = self.sub_total + self.gst_total;
            }
        };

        //SERVICE INVOICE ITEMS CALCULATION
        $scope.serviceInvoiceItemCalc = function() {
            self.table_qty = 0;
            self.table_rate = 0;
            self.table_sub_total = 0;
            self.table_total = 0;
            self.table_gst_total = 0;
            self.tax_wise_total = {};
            for (i = 0; i < self.extras.tax_list.length; i++) {
                if (typeof(self.extras.tax_list[i].name) != 'undefined') {
                    self.tax_wise_total[self.extras.tax_list[i].name + '_amount'] = 0;
                }
            };

            $(self.service_invoice.service_invoice_items).each(function(key, service_invoice_item) {
                self.table_qty += parseInt(service_invoice_item.qty);
                self.table_rate = (parseFloat(self.table_rate) + parseFloat(service_invoice_item.rate)).toFixed(2);
                st = parseFloat(service_invoice_item.sub_total).toFixed(2);
                // console.log(parseFloat(self.table_sub_total));
                // console.log(parseFloat(st));

                self.table_sub_total = (parseFloat(self.table_sub_total) + parseFloat(st)).toFixed(2);
                // console.log(parseFloat(self.table_sub_total));

                for (i = 0; i < self.extras.tax_list.length; i++) {
                    tax_obj = self.extras.tax_list[i];
                    if (service_invoice_item[tax_obj.name]) {
                        tax = parseFloat(service_invoice_item[tax_obj.name].amount).toFixed(2);
                        self.table_gst_total = parseFloat(self.table_gst_total) + parseFloat(tax);
                        if (typeof(self.tax_wise_total[tax_obj.name + '_amount']) == 'undefined') {
                            self.tax_wise_total[tax_obj.name + '_amount'] = 0;
                        }
                        self.tax_wise_total[tax_obj.name + '_amount'] += parseFloat(tax);
                        // self.table_sub_total = (parseFloat(self.table_sub_total) + parseFloat(tax)).toFixed(2);
                        // console.log(parseFloat(self.table_sub_total));
                    }
                };
                // console.log(parseFloat(self.table_sub_total));
                self.table_total = parseFloat(self.table_total) + parseFloat(service_invoice_item.total); // parseFloat(self.table_sub_total) + parseFloat(self.table_gst_total);

            });
            $scope.$apply()
        }

        var form_id = '#form';
        var v = jQuery(form_id).validate({
            ignore: '',
            submitHandler: function(form) {
                // var submitButtonValue =  $(this.submitButton).attr("data-id");
                var submitButtonId = $(this.submitButton).attr("id");
                var comment_value = $('#comments').val();
                if (submitButtonId == 'reject' && comment_value == '') {
                    $(submitButtonId).button('reset');
                    $noty = new Noty({
                        type: 'error',
                        layout: 'topRight',
                        text: 'Comments field is required',
                        animation: {
                            speed: 500 // unavailable - no need
                        },
                    }).show();
                    setTimeout(function() {
                        $noty.close();
                    }, 2000);
                    return false;
                }
                $(submitButtonId).button('loading');
                $.ajax({
                        url: laravel_routes['updateApprovalStatus'],
                        method: "POST",
                        data: {
                            id: $('#id').val(),
                            approval_type_id: $('#approval_type_id').val(),
                            comments: $('#comments').val(),
                            status_name: submitButtonId,
                        },
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                    })
                    .done(function(res) {
                        // console.log(res.success);
                        if (!res.success) {
                            $(submitButtonId).button('reset');
                            var errors = '';
                            for (var i in res.errors) {
                                errors += '<li>' + res.errors[i] + '</li>';
                            }
                            $noty = new Noty({
                                type: 'success',
                                layout: 'topRight',
                                text: errors,
                                animation: {
                                    speed: 500 // unavailable - no need
                                },
                            }).show();
                            setTimeout(function() {
                                $noty.close();
                            }, 3000);
                        } else {
                            if (res.message == 'Rejected') {
                                $('#cancel_request_reject_modal').modal('hide');
                                $noty = new Noty({
                                    type: 'success',
                                    layout: 'topRight',
                                    text: 'CN/DN ' + res.message + ' Successfully',
                                    animation: {
                                        speed: 500 // unavailable - no need
                                    },
                                }).show();
                            } else {
                                $('#cn_dn_approval_modal').modal('hide');
                                $noty = new Noty({
                                    type: 'success',
                                    layout: 'topRight',
                                    text: 'CN/DN ' + res.message + ' Successfully',
                                    animation: {
                                        speed: 500 // unavailable - no need
                                    },
                                }).show();
                            }
                            setTimeout(function() {
                                $noty.close();
                            }, 3000);
                            $timeout(function() {
                                $location.path('/service-invoice-pkg/cn-dn/approval/approval-level/' + $routeParams.approval_type_id + '/list/');
                            }, 900);
                            $scope.$apply()
                        }
                    })
                    .fail(function(xhr) {
                        $(submitButtonId).button('reset');
                        $noty = new Noty({
                            type: 'error',
                            layout: 'topRight',
                            text: 'Something went wrong at server',
                            animation: {
                                speed: 500 // unavailable - no need
                            },
                        }).show();
                        setTimeout(function() {
                            $noty.close();
                        }, 3000);
                    });

            },
        });
    }
});