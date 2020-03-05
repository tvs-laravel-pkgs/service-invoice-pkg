app.config(['$routeProvider', function($routeProvider) {

    $routeProvider.
    //SERVICE INVOICE
    when('/service-invoice-pkg/service-invoice/list', {
        template: '<service-invoice-list></service-invoice-list>',
        title: 'CN/DNs',
    }).
    when('/service-invoice-pkg/service-invoice/add/:type_id', {
        // template: '<serv-inv-form></serv-inv-form>',
        template: '<service-invoice-form></service-invoice-form>',
        title: 'Add CN/DN',
    }).
    when('/service-invoice-pkg/service-invoice/edit/:type_id/:id', {
        template: '<service-invoice-form></service-invoice-form>',
        title: 'Edit CN/DN',
    }).
    when('/service-invoice-pkg/service-invoice/view/:type_id/:id', {
        template: '<service-invoice-view></service-invoice-view>',
        title: 'View CN/DN',
    }).

    //SERVICE INVOICE APPROVALS
    when('/service-invoice-pkg/cn-dn/approval/approval-level/:approval_level_id/list/', {
        template: '<service-invoice-approval-list></service-invoice-approval-list>',
        title: 'CN/DN Approval List',
    }).
    when('/service-invoice-pkg/cn-dn/approval/approval-level/:approval_type_id/view/:type_id/:id', {
        template: '<service-invoice-approval-view></service-invoice-approval-view>',
        title: 'CN/DN Approval View',
    }).

    //SERVICE ITEM CATEGORIES
    when('/service-invoice-pkg/service-item-category/list', {
        template: '<service-item-category-list></service-item-category-list>',
        title: 'Service Item Categories',
    }).
    when('/service-invoice-pkg/service-item-category/add', {
        template: '<service-item-category-form></service-item-category-form>',
        title: 'Add Service Item Category',
    }).
    when('/service-invoice-pkg/service-item-category/edit/:id', {
        template: '<service-item-category-form></service-item-category-form>',
        title: 'Edit Service Item Category',
    }).

    //SERVICE ITEMS
    when('/service-invoice-pkg/service-item/list', {
        template: '<service-item-list></service-item-list>',
        title: 'Service Items',
    }).
    when('/service-invoice-pkg/service-item/add', {
        template: '<service-item-form></service-item-form>',
        title: 'Add Service Item',
    }).
    when('/service-invoice-pkg/service-item/edit/:id', {
        template: '<service-item-form></service-item-form>',
        title: 'Edit Service Item',
    });


}]);

app.component('serviceInvoiceList', {
    templateUrl: service_invoice_list_template_url,
    controller: function($http, $location, HelperService, $scope, $routeParams, $rootScope, $element, $mdSelect, $timeout) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.create_cn = self.hasPermission('create-cn');
        self.create_dn = self.hasPermission('create-dn');
        self.import_cn_dn = self.hasPermission('import-cn-dn');
        $http.get(
            get_service_invoice_filter_url
        ).then(function(response) {
            self.extras = response.data.extras;
            $rootScope.loading = false;
            //console.log(self.extras);
        });
        var dataTable;
        setTimeout(function() {
            var table_scroll;
            table_scroll = $('.page-main-content').height() - 37;
            dataTable = $('#service-invoice-table').DataTable({
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
                    url: laravel_routes['getServiceInvoiceList'],
                    type: "GET",
                    dataType: "json",
                    data: function(d) {
                        d.invoice_number = $('#invoice_number').val();
                        d.invoice_date = $('#invoice_date').val();
                        d.type_id = $('#type_id').val();
                        d.branch_id = $('#branch_id').val();
                        d.sbu_id = $('#sbu_id').val();
                        d.category_id = $('#category_id').val();
                        d.sub_category_id = $('#sub_category_id').val();
                        d.customer_id = $('#customer_id').val();
                        d.status_id = $('#status_id').val();
                    },
                },

                columns: [
                    { data: 'child_checkbox', searchable: false },
                    { data: 'action', searchable: false, class: 'action' },
                    { data: 'document_date', searchable: false },
                    { data: 'number', name: 'service_invoices.number', searchable: true },
                    { data: 'type_name', name: 'configs.name', searchable: true },
                    { data: 'status', name: 'approval_type_statuses.status', searchable: false },
                    { data: 'branch', name: 'outlets.code', searchable: true },
                    { data: 'sbu', name: 'sbus.name', searchable: true },
                    { data: 'category', name: 'service_item_categories.name', searchable: true },
                    { data: 'sub_category', name: 'service_item_sub_categories.name', searchable: true },
                    { data: 'customer_code', name: 'customers.code', searchable: true },
                    { data: 'customer_name', name: 'customers.name', searchable: true },
                    { data: 'invoice_amount', searchable: false, class: 'text-right' },
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
        function RefreshTable() {
            $('#service-invoice-table').DataTable().ajax.reload();
        }
        
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

        self.exportServiceInvoicesToExcelUrl = exportServiceInvoicesToExcelUrl;
        self.csrf_token = $('meta[name="csrf-token"]').attr('content');
        var filter_form_id = '#filter-form';
        var filter_form_v = jQuery(filter_form_id).validate({
            errorPlacement: function(error, element) {
                if (element.hasClass("dynamic_date")) {
                    error.insertAfter(element.parent("div"));
                } else {
                    error.insertAfter(element);
                }
            },
            ignore: '',
            rules: {
                'invoice_date': {
                    required: true,
                },
            },
            submitHandler: function(form) {
                form.submit();
                $('#addServiceItem').button('loading');
            },
        });

        $(".search_clear").on("click", function() {
            $('#search').val('');
            $('#service-invoice-table').DataTable().search('').draw();
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
                    laravel_routes['sendMultipleApproval'], {
                        send_for_approval: send_for_approval,
                    }
                ).then(function(response) {
                    if (response.data.success == true) {
                        custom_noty('success', response.data.message);
                        $timeout(function() {
                            // $('#service-invoice-table').DataTable().ajax.reload();
                            RefreshTable();
                            // $scope.$apply();
                        }, 1000);
                    } else {
                        custom_noty('error', response.data.errors);
                    }
                });
            } else {
                custom_noty('error', 'Please Select Checkbox');
            }
        })
        $('.refresh_table').on("click", function() {
            RefreshTable();
        });
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

        $scope.sendApproval = function($id, $send_to_approval) {
            $('#approval_id').val($id);
            $('#next_status').val($send_to_approval);
        }
        $scope.approvalConfirm = function() {
            $id = $('#approval_id').val();
            $send_to_approval = $('#next_status').val();
            $http.post(
                laravel_routes['saveApprovalStatus'], {
                    id: $id,
                    send_to_approval: $send_to_approval,
                }
            ).then(function(response) {
                if (response.data.success == true) {
                    custom_noty('success', response.data.message);
                    $('#service-invoice-table').DataTable().ajax.reload();
                    $scope.$apply();
                } else {
                    custom_noty('error', response.data.errors);
                }
            });
        }

        // window.onpopstate = function(e) { window.history.forward(1); }
        $rootScope.loading = false;
    }
});

//------------------------------------------------------------------------------------------------------------------------
//------------------------------------------------------------------------------------------------------------------------
app.component('serviceInvoiceForm', {
    templateUrl: service_invoice_form_template_url,
    controller: function($http, $location, HelperService, $routeParams, $rootScope, $scope, $timeout, $mdSelect, $window) {
        if ($routeParams.type_id == 1060 || $routeParams.type_id == 1061) {} else {
            $location.path('/page-not-found')
            return;
        }
        $form_data_url = typeof($routeParams.id) == 'undefined' ? service_invoice_get_form_data_url + '/' + $routeParams.type_id : service_invoice_get_form_data_url + '/' + $routeParams.type_id + '/' + $routeParams.id;
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;
        self.type_id = $routeParams.type_id;
        self.enable_service_item_md_change = true;
        var attachment_removal_ids = [];

        $http.get(
            $form_data_url
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
            self.list_url = service_invoice_list_url;
            self.service_invoice = response.data.service_invoice;
            self.customer = {};
            self.extras = response.data.extras;
            self.config_values = response.data.config_values;
            self.action = response.data.action;
            // console.log(self.service_invoice);
            if (self.action == 'Edit') {
                $timeout(function() {
                    $scope.getServiceItemSubCategoryByServiceItemCategory(self.service_invoice.service_item_sub_category.category_id);
                }, 1000);
                $timeout(function() {
                    $scope.getSbuByBranch(self.service_invoice.branch_id);
                }, 1200);
                $timeout(function() {
                    $scope.serviceInvoiceItemCalc();
                }, 1500);

                //ATTACHMENTS
                if (self.service_invoice.attachments.length) {
                    $(self.service_invoice.attachments).each(function(key, attachment) {
                        var design = '<div class="imageuploadify-container" data-attachment_id="' + attachment.id + '" style="margin-left: 0px; margin-right: 0px;">' +
                            '<div class="imageuploadify-btn-remove"><button type="button" class="btn btn-danger glyphicon glyphicon-remove"></button></div>' +
                            '<div class="imageuploadify-details"><div class="imageuploadify-file-icon"></div><span class="imageuploadify-file-name">' + attachment.name + '' +
                            '</span><span class="imageuploadify-file-type">image/jpeg</span>' +
                            '<span class="imageuploadify-file-size">369960</span></div>' +
                            '</div>';
                        $('.imageuploadify-images-list').append(design);
                    });
                }
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

        /* Modal Md Select Hide */
        $('.modal').bind('click', function(event) {
            if ($('.md-select-menu-container').hasClass('md-active')) {
                $mdSelect.hide();
            }
        });

        // $(document).bind('click', function(event) {
        //     $mdSelect.hide();// });
        // $window.addEventListener('click', function(e) {
        //     $mdSelect.hide();// })
        /* Image Uploadify Funtion */
        // $("input[name='proposal_attachments[]']").imageuploadify();
        // console.log(self.config_values);
        $('.image_uploadify').imageuploadify();

        var min_offset = '';
        var max_offset = '';
        setTimeout(function() {
            if (self.config_values != '') {
                $.each(self.config_values, function(index, value) {
                    if (value.entity_type_id == '15' && value.name != '') {
                        min_offset = '-' + value.name + 'd';
                    } else if (value.entity_type_id == '15' && value.name == '') {
                        min_offset = '';
                    } else if (value.entity_type_id == '16' && value.name != '') {
                        max_offset = '+' + value.name + 'd';
                    } else if (value.entity_type_id == '16' && value.name == '') {
                        max_offset = 'today';
                    }
                });
            } else {
                min_offset = '';
                max_offset = 'today';
            }
            $('.docDatePicker').bootstrapDP({
                format: "dd-mm-yyyy",
                autoclose: "true",
                todayHighlight: true,
                startDate: min_offset,
                endDate: max_offset
            });
        }, 1000);

        // //ATTACHMENT REMOVE
        // $(document).on('click', ".main-wrap .imageuploadify-container .imageuploadify-btn-remove button", function() {
        //     var attachment_id = $(this).parent().parent().data('attachment_id');
        //     attachment_removal_ids.push(attachment_id);
        //     $('#attachment_removal_ids').val(JSON.stringify(attachment_removal_ids));
        //     $(this).parent().parent().remove();
        // });

        //OBJECT EMPTY CHECK
        $scope.isObjectEmpty = function(objvar) {
            if (objvar) {
                return Object.keys(objvar).length === 0;
            }
        }

        //SEARCH FIELD
        self.searchField = function(query, field_id) {
            if (query && field_id) {
                return new Promise(function(resolve, reject) {
                    $http
                        .post(
                            search_field_url, {
                                key: query,
                                field_id: field_id,
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

        //SEARCH BRANCH
        self.searchBranch = function(query) {
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
                return
            }
            $scope.getSbuByBranch(self.service_invoice.branch.id);
        }

        //BRANCH CHANGED
        self.branchChanged = function() {
            self.service_invoice.sbu_id = '';
            self.extras.sbu_list = [];

            self.service_invoice.service_invoice_items = [];
            //SERVICE INVOICE ITEMS TABLE CALC
            $timeout(function() {
                $scope.serviceInvoiceItemCalc();
            }, 1000);
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
        self.getCustomerDetails = function() {
            if (self.service_invoice.customer == null) {
                return
            }
            $http.post(
                get_customer_info_url, {
                    customer_id: self.service_invoice.customer.id,
                }
            ).then(function(response) {
                if (response.data.success) {
                    self.customer = response.data.customer;
                } else {
                    custom_noty('error', response.data.error);
                }
            });
        }

        self.customerChanged = function() {
            self.customer = {};
            self.service_invoice.service_invoice_items = [];
            //SERVICE INVOICE ITEMS TABLE CALC
            $timeout(function() {
                $scope.serviceInvoiceItemCalc();
            }, 1000);
        }

        //SEARCH SERVICE ITEM
        self.searchServiceItem = function(query, type_id) {
            if (query) {
                return new Promise(function(resolve, reject) {
                    $http
                        .post(
                            search_service_item_url, {
                                key: query,
                                type_id: self.type_id,
                                category_id: $('#category_id').val(),
                                sub_category_id: $('#sub_category_id').val(),
                            }
                        )
                        .then(function(response) {
                            resolve(response.data);
                            self.enable_service_item_md_change = true;
                        });
                    //reject(response);
                });
            } else {
                return [];
            }
        }

        //GET SERVICE ITEM DETAILS
        self.getServiceItemDetails = function() {
            if (!self.service_item) {
                return
            }
            if (self.enable_service_item_md_change) {
                $http.post(
                    get_service_item_info_url, {
                        service_item_id: self.service_item.id,
                        btn_action: 'add',
                        branch_id: self.service_invoice.branch.id,
                        customer_id: self.service_invoice.customer.id,
                    }
                ).then(function(response) {
                    if (response.data.success) {
                        self.service_item_detail = response.data.service_item;
                        // console.log(response.data.service_item);
                        //AMOUNT CALCULATION
                        $scope.totalAmountCalc();
                    } else {
                        custom_noty('error', response.data.error);
                    }
                });
            }
        }

        self.serviceItemChanged = function() {
            self.service_item_detail = {};
        }


        //GET SBU BY BRANCH
        $scope.getSbuByBranch = function(branch_id) {
            self.extras.sbu_list = [];
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

        //GET SERVICE ITEM SUB CATEGORY BY CATEGORY
        $scope.getServiceItemSubCategoryByServiceItemCategory = function(category_id) {
            self.extras.sub_category_list = [];
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

        //PERCENTAGE CALC
        $scope.percentage = function(num, per) {
            return (num / 100) * per;
        }

        //PARSEINT
        self.parseInt = function(num) {
            return parseInt(num);
        }

        $("#qty").val(1);
        //ADD SERVICE INVOICE ITEM
        $scope.addItem = function() {
            self.add_service_action = 'add';
            self.action_title = 'Add';
            self.update_item_key = '';
            self.description = '';
            // self.qty = '';
            self.qty = 1;
            self.rate = '';
            self.sub_total = '';
            self.total = '';
            self.service_item = '';
            self.service_item_detail = '';
            // console.log(' == add btn ==');
            // console.log(self.service_item_detail);
        }
        //EDIT SERVICE INVOICE ITEM
        $scope.editServiceItem = function(service_invoice_item_id, description, qty, rate, index) {
            if (service_invoice_item_id) {
                self.enable_service_item_md_change = false;
                self.add_service_action = false;
                self.action_title = 'Update';
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

        //REMOVE SERVICE INVOICE ITEM
        $scope.removeServiceItem = function(service_invoice_item_id, index) {
            self.service_invoice_item_removal_id = [];
            if (service_invoice_item_id) {
                self.service_invoice_item_removal_id.push(service_invoice_item_id);
                $('#service_invoice_item_removal_ids').val(JSON.stringify(self.service_invoice_item_removal_id));
            }
            self.service_invoice.service_invoice_items.splice(index, 1);

            //SERVICE INVOICE ITEMS TABLE CALC
            $scope.serviceInvoiceItemCalc();
        }

        //ITEM TO INVOICE TOTAL AMOUNT CALC
        $scope.totalAmountCalc = function() {
            self.sub_total = 0;
            self.total = 0;
            self.gst_total = 0;
            if (self.qty && self.rate) {
                self.sub_total = self.qty * self.rate;
                self.sub_total = self.rate;
                if (self.service_item_detail.tax_code != null) {
                    if (self.service_item_detail.tax_code.taxes.length > 0) {
                        $(self.service_item_detail.tax_code.taxes).each(function(key, tax) {
                            tax.pivot.amount = $scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2);
                            self.gst_total += parseFloat($scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2));
                        });
                    }
                }
                self.total = parseFloat(self.sub_total) + parseFloat(self.gst_total);
            }
        };

        //SERVICE INVOICE ITEMS CALCULATION
        $scope.serviceInvoiceItemCalc = function() {
            self.table_qty = 0;
            self.table_rate = 0;
            self.table_gst_total = 0;
            self.table_sub_total = 0;
            self.table_total = 0;
            self.tax_wise_total = {};
            for (i = 0; i < self.extras.tax_list.length; i++) {
                if (typeof(self.extras.tax_list[i].name) != 'undefined') {
                    self.tax_wise_total[self.extras.tax_list[i].name + '_amount'] = 0;
                }
            };

            $(self.service_invoice.service_invoice_items).each(function(key, service_invoice_item) {
                self.table_qty += parseInt(service_invoice_item.qty);
                self.table_rate = (parseFloat(self.table_rate) + parseFloat(service_invoice_item.rate)).toFixed(2);
                // st = parseFloat(service_invoice_item.sub_total).toFixed(2);
                // console.log(parseFloat(self.table_sub_total));
                self.table_sub_total = (parseFloat(self.table_rate)).toFixed(2); // + parseFloat(st)).toFixed(2);
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
                self.table_total = parseFloat(self.table_total) + parseFloat(service_invoice_item.total); // parseFloat(self.table_sub_total) + parseFloat(self.table_gst_total);

            });
            $scope.$apply()
        }

        jQuery.validator.addMethod("mdselect_multiselect_required", function(value, element) {
            if ($(element).val() == '') {
                return false;
            } else if (JSON.parse($(element).val()).length == '0') {
                return false;
            }
            return true;
        }, 'This field is required');

        jQuery.validator.addClassRules("multiselect_required", {
            mdselect_multiselect_required: true,
        });

        var service_item_form_id = '#service-invoice-item-form';
        var service_v = jQuery(service_item_form_id).validate({
            errorPlacement: function(error, element) {
                if (element.hasClass("dynamic_date")) {
                    error.insertAfter(element.parent("div"));
                } else {
                    error.insertAfter(element);
                }
            },
            ignore: '',
            rules: {
                'description': {
                    required: true,
                },
                'qty': {
                    required: true,
                    digits: true,
                },
                'amount': {
                    required: true,
                },
            },
            submitHandler: function(form) {
                let formData = new FormData($(service_item_form_id)[0]);
                $('#addServiceItem').button('loading');
                $.ajax({
                        url: laravel_routes['getServiceItem'],
                        method: "POST",
                        data: formData,
                        processData: false,
                        contentType: false,
                    })
                    .done(function(res) {
                        if (!res.success) {
                            $('#addServiceItem').button('reset');
                            var errors = '';
                            for (var i in res.errors) {
                                errors += '<li>' + res.errors[i] + '</li>';
                            }
                            custom_noty('error', errors);
                        } else {
                            // console.log(res.service_item);
                            $('#modal-cn-addnew').modal('toggle');
                            if (!self.service_invoice.service_invoice_items) {
                                self.service_invoice.service_invoice_items = [];
                            }
                            if (res.add) {
                                self.service_invoice.service_invoice_items.push(res.service_item);
                            } else {
                                var edited_service_invoice_item_primary_id = self.service_invoice.service_invoice_items[self.update_item_key].id;
                                self.service_invoice.service_invoice_items[self.update_item_key] = res.service_item;
                                self.service_invoice.service_invoice_items[self.update_item_key].id = edited_service_invoice_item_primary_id;
                            }

                            //SERVICE INVOICE ITEMS TABLE CALC
                            $scope.serviceInvoiceItemCalc();

                            $scope.$apply()
                            $('#addServiceItem').button('reset');
                            custom_noty('success', res.message);
                        }
                    })
                    .fail(function(xhr) {
                        $('#addServiceItem').button('reset');
                        custom_noty('error', 'Something went wrong at server');
                    });
            },
        });



        var form_id = '#form';
        var v = jQuery(form_id).validate({
            invalidHandler: function(event, validator) {
                custom_noty('error', 'Kindly check in each tab to fix errors');
            },
            errorPlacement: function(error, element) {
                if (element.hasClass("doc_date")) {
                    error.appendTo('.doc_date_error');
                } else {
                    error.insertAfter(element);
                }
            },
            ignore: '',
            rules: {
                'document_date': {
                    required: true,
                },
                'proposal_attachments[]': {
                    // required: true,
                },
            },
            submitHandler: function(form) {

                let formData = new FormData($(form_id)[0]);
                $('#submit').button('loading');
                $.ajax({
                        url: laravel_routes['saveServiceInvoice'],
                        method: "POST",
                        data: formData,
                        processData: false,
                        contentType: false,
                    })
                    .done(function(res) {
                        // console.log(res.success);
                        if (!res.success) {
                            $('#submit').button('reset');
                            var errors = '';
                            for (var i in res.errors) {
                                errors += '<li>' + res.errors[i] + '</li>';
                            }
                            custom_noty('error', errors);
                        } else { 
                            custom_noty('success', res.message);
                            // $location.path('/service-invoice-pkg/service-invoice/list');
                            $location.path('/service-invoice-pkg/service-invoice/view/' + $routeParams.type_id + '/' + res.service_invoice_id);
                            $scope.$apply()
                        }
                    })
                    .fail(function(xhr) {
                        $('#submit').button('reset');
                        custom_noty('error', 'Something went wrong at server');
                    });
            },
        });
    }
});

//------------------------------------------------------------------------------------------------------------------------
//------------------------------------------------------------------------------------------------------------------------
app.component('serviceInvoiceView', {
    templateUrl: service_invoice_view_template_url,
    controller: function($http, $location, HelperService, $routeParams, $rootScope, $scope, $timeout, $mdSelect, $window) {
        if ($routeParams.type_id == 1060 || $routeParams.type_id == 1061) {} else {
            $location.path('/page-not-found')
            return;
        }
        $form_data_url = typeof($routeParams.id) == 'undefined' ? service_invoice_get_view_data_url + '/' + $routeParams.type_id : service_invoice_get_view_data_url + '/' + $routeParams.type_id + '/' + $routeParams.id;
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;
        self.type_id = $routeParams.type_id;
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
                $location.path('/service-invoice-pkg/service-invoice/list')
                $scope.$apply()
            }
            self.list_url = service_invoice_list_url;
            self.service_invoice = response.data.service_invoice;
            self.customer = {};
            self.extras = response.data.extras;
            self.approval_status = response.data.approval_status;
            self.service_invoice_status = response.data.service_invoice_status;
            self.action = response.data.action;
            // console.log(self.service_invoice);
            if (self.action == 'View') {
                $timeout(function() {
                    $scope.serviceInvoiceItemCalc();
                }, 1500);

                //ATTACHMENTS
                // if (self.service_invoice.attachments.length) {
                //     $(self.service_invoice.attachments).each(function(key, attachment) {
                //         var design = '<div class="imageuploadify-container" data-attachment_id="' + attachment.id + '" style="margin-left: 0px; margin-right: 0px;">' +
                //             '<div class="imageuploadify-details"><div class="imageuploadify-file-icon"></div><span class="imageuploadify-file-name">' + attachment.name + '' +
                //             '</span><span class="imageuploadify-file-type">image/jpeg</span>' +
                //             '<span class="imageuploadify-file-size">369960</span></div>' +
                //             '</div>';
                //         $('.imageuploadify-images-list').append(design);
                //     });
                // }
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

        // $('.image_uploadify').imageuploadify();

        //PERCENTAGE CALC
        $scope.percentage = function(num, per) {
            return (num / 100) * per;
        }

        //PARSEINT
        self.parseInt = function(num) {
            // return num.toFixed(2);
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
                self.sub_total = self.rate;
                if (self.service_item_detail.tax_code != null) {
                    if (self.service_item_detail.tax_code.taxes.length > 0) {
                        $(self.service_item_detail.tax_code.taxes).each(function(key, tax) {
                            tax.pivot.amount = $scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2);
                            self.gst_total += parseFloat($scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2));
                        });
                    }
                }
                self.total = parseFloat(self.sub_total) + parseFloat(self.gst_total);
            }
        };

        //SERVICE INVOICE ITEMS CALCULATION
        $scope.serviceInvoiceItemCalc = function() {
            // self.table_qty = 0;
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
                $('#submit').button('loading');
                $.ajax({
                        url: laravel_routes['saveApprovalStatus'],
                        method: "POST",
                        data: {
                            id: $('#service_invoice_id').val(),
                            send_to_approval: $('#send_to_approval').val(),
                        },
                        headers: {
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                        },
                    })
                    .done(function(res) {
                        // console.log(res.success);
                        if (!res.success) {
                            $('#submit').button('reset');
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
                            // $('#back_button').addClass("disabled");
                            // $('#edit_button').addClass("disabled");
                            $noty = new Noty({
                                type: 'success',
                                layout: 'topRight',
                                text: res.message,
                                animation: {
                                    speed: 500 // unavailable - no need
                                },
                            }).show();
                            setTimeout(function() {
                                $noty.close();
                            }, 3000);
                            $location.path('/service-invoice-pkg/service-invoice/list');
                            $scope.$apply()
                        }
                    })
                    .fail(function(xhr) {
                        $('#submit').button('reset');
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