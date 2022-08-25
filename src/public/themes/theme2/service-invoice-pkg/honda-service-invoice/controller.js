app.config(['$routeProvider', function ($routeProvider) {

    $routeProvider.
        //SERVICE INVOICE
        when('/service-invoice-pkg/honda-service-invoice/list', {
            template: '<honda-service-invoice-list></honda-service-invoice-list>',
            title: 'CN/DNs',
        }).
        when('/service-invoice-pkg/honda-service-invoice/add/:type_id', {
            // template: '<serv-inv-form></serv-inv-form>',
            template: '<honda-service-invoice-form></honda-service-invoice-form>',
            title: 'Add CN/DN',
        }).
        when('/service-invoice-pkg/honda-service-invoice/edit/:type_id/:id', {
            template: '<honda-service-invoice-form></honda-service-invoice-form>',
            title: 'Edit CN/DN',
        }).
        when('/service-invoice-pkg/honda-service-invoice/view/:type_id/:id', {
            template: '<honda-service-invoice-view></honda-service-invoice-view>',
            title: 'View CN/DN',
        }).

        //SERVICE INVOICE APPROVALS
        when('/service-invoice-pkg/honda-cn-dn/approval/approval-level/:approval_level_id/list/', {
            template: '<honda-service-invoice-approval-list></honda-service-invoice-approval-list>',
            title: 'Honda CN/DN Approval List',
        }).
        when('/service-invoice-pkg/honda-cn-dn/approval/approval-level/:approval_type_id/view/:type_id/:id', {
            template: '<honda-service-invoice-approval-view></honda-service-invoice-approval-view>',
            title: 'Honda CN/DN Approval View',
        }).

        //SERVICE ITEM CATEGORIES
        when('/service-invoice-pkg/service-item-category/list', {
            template: '<honda-service-item-category-list></honda-service-item-category-list>',
            title: 'Service Item Categories',
        }).
        when('/service-invoice-pkg/service-item-category/add', {
            template: '<honda-service-item-category-form></honda-service-item-category-form>',
            title: 'Add Service Item Category',
        }).
        when('/service-invoice-pkg/service-item-category/edit/:id', {
            template: '<honda-service-item-category-form></honda-service-item-category-form>',
            title: 'Edit Service Item Category',
        }).

        //SERVICE ITEMS
        when('/service-invoice-pkg/service-item/list', {
            template: '<honda-service-item-list></honda-service-item-list>',
            title: 'Service Items',
        }).
        when('/service-invoice-pkg/service-item/add', {
            template: '<honda-service-item-form></honda-service-item-form>',
            title: 'Add Service Item',
        }).
        when('/service-invoice-pkg/service-item/edit/:id', {
            template: '<honda-service-item-form></honda-service-item-form>',
            title: 'Edit Service Item',
        }).

        //TCS AND GST REPORTS
        when('/service-invoice-pkg/tcs-report', {
            template: '<honda-tcs-report-form></honda-tcs-report-form>',
            title: 'TCS Report',
        }).
        when('/service-invoice-pkg/gst-report', {
            template: '<honda-gst-report-form></honda-gst-report-form>',
            title: 'GST Report',
        });

}]);

app.component('hondaServiceInvoiceList', {
    templateUrl: honda_service_invoice_list_template_url,
    controller: function ($http, $location, HelperService, $scope, $routeParams, $rootScope, $element, $mdSelect, $timeout) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.create_cn = self.hasPermission('honda-create-cn');
        self.create_dn = self.hasPermission('honda-create-dn');
        self.create_inv = self.hasPermission('honda-create-inv');
        self.import_cn_dn = self.hasPermission('honda-import-cn-dn');
        self.tcs_export = self.hasPermission('honda-tcs-export-all');
        self.gst_export = self.hasPermission('honda-gst-export');
        $http.get(
            get_honda_service_invoice_filter_url
        ).then(function (response) {
            self.extras = response.data.extras;
            $rootScope.loading = false;
            //console.log(self.extras);
        });
        var dataTable;
        setTimeout(function () {
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
                stateSaveCallback: function (settings, data) {
                    localStorage.setItem('SIDataTables_' + settings.sInstance, JSON.stringify(data));
                },
                stateLoadCallback: function (settings) {
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
                    url: laravel_routes['getHondaServiceInvoiceList'],
                    type: "GET",
                    dataType: "json",
                    data: function (d) {
                        d.invoice_number = $('#invoice_number').val();
                        d.invoice_date = $('#invoice_date').val();
                        d.type_id = $('#type_id').val();
                        d.branch_id = $('#branch_id').val();
                        d.sbu_id = $('#sbu_id').val();
                        d.category_id = $('#category_id').val();
                        // d.sub_category_id = $('#sub_category_id').val();
                        d.customer_id = $('#customer_id').val();
                        d.status_id = $('#status_id').val();
                    },
                },

                columns: [
                    { data: 'child_checkbox', searchable: false },
                    { data: 'action', searchable: false, class: 'action' },
                    { data: 'document_date', searchable: false },
                    { data: 'number', name: 'honda_service_invoices.number', searchable: true },
                    { data: 'type_name', name: 'configs.name', searchable: true },
                    { data: 'status', name: 'approval_type_statuses.status', searchable: false },
                    { data: 'branch', name: 'outlets.code', searchable: true },
                    { data: 'sbu', name: 'sbus.name', searchable: true },
                    { data: 'category', name: 'service_item_categories.name', searchable: true },
                    // { data: 'sub_category', name: 'service_item_sub_categories.name', searchable: true },
                    { data: 'to_account_type', name: 'to_account_type.name', searchable: true },
                    { data: 'customer_code', name: 'customers.code', searchable: true },
                    { data: 'customer_name', name: 'customers.name', searchable: true },
                    { data: 'invoice_amount', searchable: false, class: 'text-right' },
                ],
                "initComplete": function (settings, json) {
                    $('.dataTables_length select').select2();
                },
                rowCallback: function (row, data) {
                    $(row).addClass('highlight-row');
                },
                infoCallback: function (settings, start, end, max, total, pre) {
                    $('#table_info').html(total)
                    $('.foot_info').html('Showing ' + start + ' to ' + end + ' of ' + max + ' entries')
                },
            });
        }, 1000);
        $('.modal').bind('click', function (event) {
            if ($('.md-select-menu-container').hasClass('md-active')) {
                $mdSelect.hide();
            }
        });

        function RefreshTable() {
            $('#service-invoice-table').DataTable().ajax.reload();
        }

        $('#invoice_number').keyup(function () {
            setTimeout(function () {
                dataTable.draw();
            }, 900);
        });
        $('body').on('click', '.applyBtn', function () { //alert('sd');
            setTimeout(function () {
                dataTable.draw();
            }, 900);
        });
        $('body').on('click', '.cancelBtn', function () { //alert('sd');
            setTimeout(function () {
                dataTable.draw();
            }, 900);
        });

        $scope.onSelectedType = function (selected_type) {
            setTimeout(function () {
                $('#type_id').val(selected_type);
                dataTable.draw();
            }, 900);
        }
        $scope.getSelectedSbu = function (selected_sbu_id) {
            setTimeout(function () {
                $('#sbu_id').val(selected_sbu_id);
                dataTable.draw();
            }, 900);
        }
        $scope.getSelectedCategory = function (selected_category_id) {
            setTimeout(function () {
                $('#category_id').val(selected_category_id);
                dataTable.draw();
            }, 900);
        }
        // $scope.getSubCategory = function(selected_sub_category_id) {
        //     setTimeout(function() {
        //         $('#sub_category_id').val(selected_sub_category_id);
        //         dataTable.draw();
        //     }, 900);
        // }
        $scope.getSelectedStatus = function (selected_status_id) {
            setTimeout(function () {
                $('#status_id').val(selected_status_id);
                dataTable.draw();
            }, 900);
        }
        $scope.reset_filter = function () {
            $('#invoice_number').val('');
            $('#invoice_date').val('');
            $('#created_date').val('');
            $('#type_id').val('');
            $('#branch_id').val('');
            $('#sbu_id').val('');
            $('#category_id').val('');
            // $('#sub_category_id').val('');
            $('#customer_id').val('');
            $('#status_id').val('');
            $('#gstin').val('');
            dataTable.draw();
        }
        //GET SERVICE ITEM SUB CATEGORY BY CATEGORY
        // $scope.getServiceItemSubCategory = function(category_id) {
        //     self.extras.sub_category_list = [];
        //     if (category_id == '') {
        //         $('#sub_category_id').val('');
        //     }
        //     $('#category_id').val(category_id);
        //     dataTable.draw();
        //     if (category_id) {
        //         $.ajax({
        //                 url: get_service_item_sub_category_url + '/' + category_id,
        //                 method: "GET",
        //             })
        //             .done(function(res) {
        //                 if (!res.success) {
        //                     new Noty({
        //                         type: 'error',
        //                         layout: 'topRight',
        //                         text: res.error
        //                     }).show();
        //                 } else {
        //                     self.extras.sub_category_list = res.sub_category_list;
        //                     $scope.$apply()
        //                 }
        //             })
        //             .fail(function(xhr) {
        //                 console.log(xhr);
        //             });
        //     }
        // }
        self.export_value = function (value) {
            console.log(value);
            $("#export_type").val(value);
        }

        self.exportServiceInvoicesToExcelUrl = exportServiceInvoicesToExcelUrl;
        self.csrf_token = $('meta[name="csrf-token"]').attr('content');
        var filter_form_id = '#filter-form';
        var filter_form_v = jQuery(filter_form_id).validate({
            errorPlacement: function (error, element) {
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
                'gstin': {
                    required: function () {
                        if ($('#export_type').val() == 3) {
                            return true;
                        } else {
                            return false;
                        }
                    }
                }
            },
            submitHandler: function (form) {
                form.submit();
                $('#addServiceItem').button('loading');
            },
        });

        $(".search_clear").on("click", function () {
            $('#search').val('');
            $('#service-invoice-table').DataTable().search('').draw();
        });

        $("#search").on('keyup', function () {
            dataTable
                .search(this.value)
                .draw();
        });

        $('#send_for_approval').on('click', function () { //alert('dsf');
            if ($('.service_invoice_checkbox:checked').length > 0) {
                $('#pace').css("display", "block");
                $('#pace').addClass('pace-active');
                var send_for_approval = []
                $('input[name="child_boxes"]:checked').each(function () {
                    send_for_approval.push(this.value);
                });
                // console.log(send_for_approval);
                $http.post(
                    laravel_routes['sendHondaMultipleApproval'], {
                    send_for_approval: send_for_approval,
                }
                ).then(function (response) {
                    $('#pace').css("display", "none");
                    $('#pace').addClass('pace-inactive');
                    if (response.data.success == true) {
                        custom_noty('success', response.data.message);
                        $timeout(function () {
                            // $('#honda-service-invoice-table').DataTable().ajax.reload();
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
        $('.refresh_table').on("click", function () {
            RefreshTable();
        });
        $('#parent').on('click', function () {
            if (this.checked) {
                $('.service_invoice_checkbox').each(function () {
                    this.checked = true;
                });
            } else {
                $('.service_invoice_checkbox').each(function () {
                    this.checked = false;
                });
            }
        });
        $(document.body).on('click', '.service_invoice_checkbox', function () {
            if ($('.service_invoice_checkbox:checked').length == $('.service_invoice_checkbox').length) {
                $('#parent').prop('checked', true);
            } else {
                $('#parent').prop('checked', false);
            }
        });

        dateRangePicker();

        //SEARCH BRANCH
        self.searchBranchFilter = function (query) {
            if (query) {
                return new Promise(function (resolve, reject) {
                    $http
                        .post(
                            honda_search_branch_url, {
                            key: query,
                        }
                        )
                        .then(function (response) {
                            resolve(response.data);
                        });
                    //reject(response);
                });
            } else {
                return [];
            }
        }
        //GET BRANCH DETAILS
        self.getBranchDetails = function () {
            if (self.service_invoice.branch == null) {
                $('#branch_id').val('');
                dataTable.draw();
                return
            }
            $scope.getSbuByBranch(self.service_invoice.branch.id);
        }
        //GET SBU BY BRANCH
        $scope.getSbuByBranch = function (branch_id) {
            self.extras.sbu_list = [];
            $('#branch_id').val(branch_id);
            dataTable.draw();
            if (branch_id) {
                $.ajax({
                    url: get_sbu_url + '/' + branch_id,
                    method: "GET",
                })
                    .done(function (res) {
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
                    .fail(function (xhr) {
                        console.log(xhr);
                    });
            }
        }
        //BRANCH CHANGED
        self.branchChanged = function () {
            self.service_invoice.sbu_id = '';
            self.extras.sbu_list = [];
        }
        //SEARCH CUSTOMER
        self.searchHondaCustomer = function (query) {
            if (query) {
                return new Promise(function (resolve, reject) {
                    $http
                        .post(
                            honda_service_invoice_search_customer_url, {
                            key: query,
                        }
                        )
                        .then(function (response) {
                            resolve(response.data);
                        });
                    //reject(response);
                });
            } else {
                return [];
            }
        }
        //GET CUSTOMER DETAILS
        $scope.getCustomerDetails = function (selected_customer_id) {
            $('#customer_id').val(selected_customer_id);
            dataTable.draw();
        }

        $scope.sendApproval = function ($id, $send_to_approval) {
            $('#approval_id').val($id);
            $('#next_status').val($send_to_approval);
        }
        $scope.approvalConfirm = function () {
            $id = $('#approval_id').val();
            $send_to_approval = $('#next_status').val();
            $('#pace').css("display", "block");
            $('#pace').addClass('pace-active');
            $http.post(
                laravel_routes['saveHondaApprovalStatus'], {
                id: $id,
                send_to_approval: $send_to_approval,
            }
            ).then(function (response) {
                $('#pace').css("display", "none");
                $('#pace').addClass('pace-inactive');
                if (response.data.success == true) {
                    custom_noty('success', response.data.message);
                    $('#service-invoice-table').DataTable().ajax.reload();
                    $scope.$apply();
                } else {
                    custom_noty('error', response.data.errors);
                }
            });
        }

        $scope.deleteIRN = function ($id) {
            $("#cancel_irn_id").val($id);
            $("#cancel_type").val("IRN");
        }
        $scope.deleteB2C = function ($id) {
            $("#cancel_irn_id").val($id);
            $("#cancel_type").val("B2C");
        }
        $scope.cancelIRN = function () {
            $('#pace').css("display", "block");
            $('#pace').addClass('pace-active');
            $id = $('#cancel_irn_id').val();
            $cancel_type = $('#cancel_type').val();
            // console.log($id);
            // console.log($cancel_type);
            // return;
            $http.get(
                laravel_routes['cancelHondaIrn'], {
                params: {
                    id: $id,
                    type: $cancel_type,
                }
            }
            ).then(function (response) {
                $('#pace').css("display", "none");
                $('#pace').addClass('pace-inactive');
                if (response.data.success == true) {
                    custom_noty('success', response.data.message);
                    $('#service-invoice-table').DataTable().ajax.reload();
                    $scope.$apply();
                } else {
                    custom_noty('error', response.data.errors);
                }
            });
        }

        $scope.cholaPdfDownload = function (service_invoice_id) {
            $http.get(
                laravel_routes['cholaHondaPdfCreate'], {
                params: {
                    id: service_invoice_id,
                }
            }
            ).then(function (res) {
                console.log(res);
                base_url + '/' + window.open(res.data.file_name_path, '_blank').focus();
            });

        }

        // window.onpopstate = function(e) { window.history.forward(1); }
        $rootScope.loading = false;
    }
});

//------------------------------------------------------------------------------------------------------------------------
//------------------------------------------------------------------------------------------------------------------------
app.component('hondaServiceInvoiceForm', {
    templateUrl: honda_service_invoice_form_template_url,
    controller: function ($http, $location, HelperService, $routeParams, $rootScope, $scope, $timeout, $mdSelect, $window) {
        if ($routeParams.type_id == 1060 || $routeParams.type_id == 1061 || $routeParams.type_id == 1062) { } else {
            $location.path('/page-not-found')
            return;
        }
        $form_data_url = typeof ($routeParams.id) == 'undefined' ? honda_service_invoice_get_form_data_url + '/' + $routeParams.type_id : honda_service_invoice_get_form_data_url + '/' + $routeParams.type_id + '/' + $routeParams.id;
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.allow_e_invoice_selection = self.hasPermission('honda-allow-e-invoice-selection');
        self.e_invoice_only = self.hasPermission('honda-e-invoice-only');
        self.without_e_invoice_only = self.hasPermission('honda-without-e-invoice-only');
        self.angular_routes = angular_routes;
        self.type_id = $routeParams.type_id;
        self.enable_service_item_md_change = true;
        var attachment_removal_ids = [];
        $http.get(
            $form_data_url
        ).then(function (response) {
            console.log(response)
            if (!response.data.success) {
                new Noty({
                    type: 'error',
                    layout: 'topRight',
                    text: response.data.error,
                }).show();
                $location.path('/service-invoice-pkg/honda-service-invoice/list')
                $scope.$apply()
            }
            self.list_url = honda_service_invoice_list_url;
            self.service_invoice = response.data.service_invoice;
            self.customer = {};
            self.e_invoice_uom = {};
            self.extras = response.data.extras;
            self.config_values = response.data.config_values;
            self.tcs_limit = response.data.tcs_limit;
            self.action = response.data.action;
            console.log(response);
            if (self.action == 'Edit') {
                // $timeout(function() {
                //     $scope.getServiceItemSubCategoryByServiceItemCategory(self.service_invoice.service_item_sub_category.category_id);
                // }, 1000);
                // console.log(self.service_invoice.to_account_type_id);
                if (self.service_invoice.to_account_type_id == 1440 || self.service_invoice.to_account_type_id == 1441) { //CUSTOMER || VENDOE
                    $timeout(function () {
                        self.customer = self.service_invoice.customer;
                        self.customer_addresses = response.data.extras.addresses;
                        // $rootScope.getCustomer(self.service_invoice.customer_id);
                        if (self.service_invoice.to_account_type_id == 1440) {
                            $scope.customerSelected();

                        }
                        if (self.service_invoice.to_account_type_id == 1441) {
                            $scope.vendorSelected(); //USED FOR GET FULL ADDRESS
                        }

                    }, 1200);
                }
                $timeout(function () {
                    $scope.getSbuByBranch(self.service_invoice.branch_id);
                }, 1200);
                $timeout(function () {
                    $scope.serviceInvoiceItemCalc();
                }, 1500);
                // $timeout(function() {
                // self.customer_addresses = response.data.extras.addresses;
                // }, 1300);
                // $scope.apply();

                //ATTACHMENTS
                if (self.service_invoice.attachments.length) {
                    $(self.service_invoice.attachments).each(function (key, attachment) {
                        var design = '<div class="imageuploadify-container" data-attachment_id="' + attachment.id + '" style="margin-left: 0px; margin-right: 0px;">' +
                            '<div class="imageuploadify-btn-remove"><button type="button" class="btn btn-danger glyphicon glyphicon-remove"></button></div>' +
                            '<div class="imageuploadify-details"><div class="imageuploadify-file-icon"></div><span class="imageuploadify-file-name">' + attachment.name + '' +
                            '</span><span class="imageuploadify-file-type">image/jpeg</span>' +
                            '<span class="imageuploadify-file-size">369960</span></div>' +
                            '</div>';
                        $('.imageuploadify-images-list').append(design);
                    });
                }
            } else {
                //CURRENT DATE SELECTED IN DOC DATE
                var d = new Date();
                var val = ('0' + d.getDate()).slice(-2) + "-" + ('0' + (d.getMonth() + 1)).slice(-2) + "-" + d.getFullYear();
                // console.log(val);
                setTimeout(function () {
                    $("#doc_date").val(val);
                    $("#hid_document_date").val(val);
                }, 1000);
                self.service_invoice.is_reverse_charge_applicable = 0;
                self.service_invoice.is_service = 1;
            }
            $rootScope.loading = false;
        });

        //FOR FILTER
        if (self.e_invoice_only && self.without_e_invoice_only) {
            self.e_invoice_registration = [
                { id: '', name: 'Selcet E-Invoice' },
                { id: '1', name: 'E-Invoice Only' },
                { id: '0', name: 'Without E-Invoice only' },
            ];
        } else if (self.e_invoice_only) {
            self.e_invoice_registration = [
                { id: '', name: 'Selcet E-Invoice' },
                { id: '1', name: 'E-Invoice Only' },
            ];
        } else if (self.without_e_invoice_only) {
            self.e_invoice_registration = [
                { id: '', name: 'Selcet E-Invoice' },
                { id: '0', name: 'Without E-Invoice only' },
            ];
        } else {
            self.e_invoice_registration = [
                { id: '', name: 'Selcet E-Invoice' },
            ];
        }
        // setTimeout(function() {
        //     $scope.onSelectedEInvoice = function(val) {
        //         console.log(val);
        //         var e_invoice_selection = val;
        //         if (e_invoice_selection) {
        //             console.log('if');
        //             self.e_invoice_selection = e_invoice_selection;
        //         } else {
        //             console.log('else');
        //             self.e_invoice_selection = 1;
        //         }
        //     }
        // }, 1500);

        /* Tab Funtion */
        $('.btn-nxt').on("click", function () {
            $('.cndn-tabs li.active').next().children('a').trigger("click");
            tabPaneFooter();
        });
        $('.btn-prev').on("click", function () {
            $('.cndn-tabs li.active').prev().children('a').trigger("click");
            tabPaneFooter();
        });

        /* Modal Md Select Hide */
        $('.modal').bind('click', function (event) {
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
        setTimeout(function () {
            if (self.config_values != '') {
                $.each(self.config_values, function (index, value) {
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
                // endDate: max_offset
                endDate: 'today'
            });
            $('.invoiceDatePicker').bootstrapDP({
                format: "dd-mm-yyyy",
                autoclose: "true",
                todayHighlight: true,
                // startDate: min_offset,
                endDate: 'today'
            });
        }, 7000);

        // //ATTACHMENT REMOVE
        // $(document).on('click', ".main-wrap .imageuploadify-container .imageuploadify-btn-remove button", function() {
        //     var attachment_id = $(this).parent().parent().data('attachment_id');
        //     attachment_removal_ids.push(attachment_id);
        //     $('#attachment_removal_ids').val(JSON.stringify(attachment_removal_ids));
        //     $(this).parent().parent().remove();
        // });

        //OBJECT EMPTY CHECK
        $scope.isObjectEmpty = function (objvar) {
            if (objvar) {
                return Object.keys(objvar).length === 0;
            }
        }

        //SEARCH FIELD
        self.searchField = function (query, field_id) {
            if (query && field_id) {
                return new Promise(function (resolve, reject) {
                    $http
                        .post(
                            search_field_url, {
                            key: query,
                            field_id: field_id,
                        }
                        )
                        .then(function (response) {
                            resolve(response.data);
                        });
                    //reject(response);
                });
            } else {
                return [];
            }
        }

        //SEARCH BRANCH
        self.searchBranch = function (query) {
            if (query) {
                return new Promise(function (resolve, reject) {
                    $http
                        .post(
                            honda_search_branch_url, {
                            key: query,
                        }
                        )
                        .then(function (response) {
                            resolve(response.data);
                        });
                    //reject(response);
                });
            } else {
                return [];
            }
        }

        //GET BRANCH DETAILS
        self.getBranchDetails = function () {
            if (self.service_invoice.branch == null) {
                return
            }
            $scope.getSbuByBranch(self.service_invoice.branch.id);
        }

        //BRANCH CHANGED
        self.branchChanged = function () {
            self.service_invoice.sbu_id = '';
            self.extras.sbu_list = [];

            self.service_invoice.service_invoice_items = [];
            //SERVICE INVOICE ITEMS TABLE CALC
            $timeout(function () {
                $scope.serviceInvoiceItemCalc();
            }, 1000);
        }

        $scope.getAccountType = function (id) {
            console.log(id);
            self.CustomerSearchText = "";
            self.customer = {};
            self.service_invoice.service_invoice_items = [];
        }

        self.searchHondaCustomer = $rootScope.searchHondaCustomer;
        // console.log(self.searchHondaCustomer);

        //GET CUSTOMER DETAILS
        $scope.customerSelected = function () {
            console.log('test');
            $('#pace').css("display", "block");
            $('#pace').addClass('pace-active');
            console.log(self.service_invoice.customer);
            if (self.service_invoice.customer || self.service_invoice.customer != null) {
                 // var res = $rootScope.getCustomer(self.service_invoice.customer).then(function(res) {
                var res = $rootScope.getHondaCustomerAddress(self.service_invoice.customer).then(function (res) {
                    console.log(res);
                    if (!res.data.success) {
                        $('#pace').css("display", "none");
                        $('#pace').addClass('pace-inactive');
                        custom_noty('error', res.data.error);
                        return;
                    }
                    $('#pace').addClass('pace-inactive');
                    $('#pace').css("display", "none");
                    console.log(res.data);
                    self.customer = res.data.customer;
                    self.service_invoice.customer.id = res.data.customer.id;
                    if (res.data.customer_address.length > 1) {
                        self.multiple_address = true;
                        self.single_address = false;
                        self.customer_addresses = res.data.customer_address;
                        console.log(self.customer_address);
                    } else {
                        self.multiple_address = false;
                        self.single_address = true;
                        self.customer.state_id = res.data.customer_address[0].state_id;
                        self.customer.gst_number = res.data.customer_address[0].gst_number;
                        // self.customer = res.data.customer;
                        // self.service_invoice.customer.id = res.data.customer.id;
                        self.customer_address = res.data.customer_address[0];
                        console.log(self.customer + 'single');
                        if (res.data.customer_address[0].gst_number) {
                            setTimeout(function () {
                                $scope.checkCustomerGSTIN(res.data.customer_address[0].gst_number, self.customer.name);
                            }, 1000);
                        }
                    }
                });
            } else {
                $('#pace').css("display", "none");
                $('#pace').addClass('pace-inactive');
                self.customer = {};
                self.customer_address = {};
                self.customer_addresses = {};
                self.service_invoice.service_invoice_items = [];
            }
        }

        $scope.selectedAddress = function (address) {
            console.log(address);
            self.service_invoice.service_invoice_items = [];
            if ($('.address:checked').length > 1) {
                custom_noty('error', 'Already one address selected!');
                return;
            } else {
                // console.log('2');
                self.customer.state_id = address.state_id;
                self.customer.gst_number = address.gst_number;
                console.log(self.customer);
                if (address.gst_number) {
                    if ($('.address').is(":checked") == true) {
                        setTimeout(function () {
                            $scope.checkCustomerGSTIN(address.gst_number, self.customer.name);
                        }, 1000);
                    }
                }
            }
        }

        $scope.checkCustomerGSTIN = function (gstin, customer) {
            // console.log(gstin);
            if (self.allow_e_invoice_selection) {
                var e_invoice_selection = $("#e_invoice_registration").val();
            } else {
                var e_invoice_selection = 1;
            }
            console.log(e_invoice_selection);
            if (e_invoice_selection == 1) {
                var customer_name = customer.toLowerCase();
                console.log(customer_name);
                $('#pace').css("display", "block");
                $('#pace').addClass('pace-active');

                if (gstin) {
                    $http.get(
                        get_gstin_details + '/' + gstin,

                    ).then(function (response) {
                        $('#pace').css("display", "none");
                        $('#pace').addClass('pace-inactive');
                        console.log(response);
                        if (!response.data.success) {
                            // showErrorNoty(response);
                            custom_noty('error', response.data.error);
                            return;
                        } else {
                            if (response.data.trade_name || response.data.legal_name) {

                                var trade_name = response.data.trade_name.toLowerCase();
                                var legal_name = response.data.legal_name.toLowerCase();
                                console.log("trade_name = " + trade_name);
                                console.log("legal_name = " + legal_name);
                                if (customer_name === legal_name) {
                                    $noty = new Noty({
                                        type: 'success',
                                        layout: 'topRight',
                                        text: 'GSTIN Registred Legal Name: ' + response.data.legal_name,
                                        animation: {
                                            speed: 1000 // unavailable - no need
                                        },
                                    }).show();
                                    setTimeout(function () {
                                        $noty.close();
                                    }, 12000);
                                    custom_noty('success', 'Customer Name Matched');
                                    $('#submit').show();
                                    $('.add_item_btn').show();
                                } else if (customer_name === trade_name) {
                                    $noty = new Noty({
                                        type: 'success',
                                        layout: 'topRight',
                                        text: 'GSTIN Registred Trade Name: ' + response.data.trade_name,
                                        animation: {
                                            speed: 1000 // unavailable - no need
                                        },
                                    }).show();
                                    setTimeout(function () {
                                        $noty.close();
                                    }, 12000);
                                    custom_noty('success', 'Customer Name Matched');
                                    $('#submit').show();
                                    $('.add_item_btn').show();
                                } else {
                                    $noty = new Noty({
                                        type: 'success',
                                        layout: 'topRight',
                                        text: 'GSTIN Registred Legal Name: ' + response.data.legal_name + ', and  GSTIN Registred Trade Name: ' + response.data.trade_name,
                                        animation: {
                                            speed: 1000 // unavailable - no need
                                        },
                                    }).show();
                                    setTimeout(function () {
                                        $noty.close();
                                    }, 15000);
                                    custom_noty('error', 'Customer Name Not Matched!');
                                    custom_noty('error', 'Not Allow To Add Invoives!');
                                    $('#submit').hide();
                                    $('.add_item_btn').hide();

                                    if(response.data.gst_status && response.data.gst_status != 'ACT'){
                                        custom_noty('error', 'In Active GSTIN!');
                                        custom_noty('error', 'Not Allow To Add Invoives!');
                                        $('#submit').hide();
                                        $('.add_item_btn').hide();   
                                    }
                                }
                            } else {
                                custom_noty('error', response.data.error);
                                custom_noty('error', 'Not Allow To Add Invoives!');
                                $('#submit').hide();
                                $('.add_item_btn').hide();
                            }
                        }

                    });
                }
            }
        }

        self.searchVendor = $rootScope.searchVendor;

        $scope.vendorSelected = function () {
            console.log('test');
            $('#pace').css("display", "block");
            $('#pace').addClass('pace-active');
            console.log(self.service_invoice.customer);
            if (self.service_invoice.customer || self.service_invoice.customer != null) {
                // var res = $rootScope.getCustomer(self.service_invoice.customer).then(function(res) {
                var res = $rootScope.getVendorAddress(self.service_invoice.customer).then(function (res) {
                    console.log(res);
                    if (!res.data.success) {
                        $('#pace').css("display", "none");
                        $('#pace').addClass('pace-inactive');
                        custom_noty('error', res.data.error);
                        return;
                    }
                    $('#pace').addClass('pace-inactive');
                    $('#pace').css("display", "none");
                    console.log(res.data);
                    self.customer = res.data.vendor;
                    self.service_invoice.customer.id = res.data.vendor.id;
                    if (res.data.vendor_address.length > 1) {
                        self.multiple_address = true;
                        self.single_address = false;
                        self.customer_addresses = res.data.vendor_address;
                        console.log(self.vendor_address);
                    } else {
                        self.multiple_address = false;
                        self.single_address = true;
                        self.customer.state_id = res.data.vendor_address[0].state_id;
                        self.customer.gst_number = res.data.vendor_address[0].gst_number;

                        self.customer_address = res.data.vendor_address[0];
                        console.log(self.customer + 'single');
                        if (res.data.vendor_address[0].gst_number) {
                            setTimeout(function () {
                                $scope.checkCustomerGSTIN(res.data.vendor_address[0].gst_number, self.vendor.name);
                            }, 1000);
                        }
                    }
                });
            } else {
                $('#pace').css("display", "none");
                $('#pace').addClass('pace-inactive');
                self.customer = {};
                self.customer_address = {};
                self.customer_addresses = {};
                self.service_invoice.service_invoice_items = [];
            }
        }

       

        console.log(self.service_invoice);
     

        //SEARCH SERVICE ITEM
        self.searchServiceItem = function (query, $to_account_type_id) {
            if (query) {
                return new Promise(function (resolve, reject) {
                    $http
                        .post(
                            search_service_item_url, {
                            key: query,
                            type_id: self.type_id,
                            category_id: $('#category_id').val(),
                            sub_category_id: $('#sub_category_id').val(),
                            is_service: $("input[name='is_service']:checked").val(),
                        }
                        )
                        .then(function (response) {
                            resolve(response.data);
                            if ($to_account_type_id == undefined || $to_account_type_id == null) {
                                custom_noty('error', 'Select Account Type!');
                                return;
                            } else {
                                if ($to_account_type_id == 1440) { //CUSTOMER
                                    if (!self.service_invoice.customer) {
                                        custom_noty('error', 'Select Customer!');
                                        return;
                                    }
                                }
                                if ($to_account_type_id == 1441) { //VENDOR
                                    if (!self.service_invoice.customer) {
                                        custom_noty('error', 'Select Vendor!');
                                        return;
                                    }
                                }
                            }
                            self.enable_service_item_md_change = true;
                        });
                    //reject(response);
                });
            } else {
                return [];
            }
        }

        //GET SERVICE ITEM DETAILS
        self.getServiceItemDetails = function ($to_account_type_id) {
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
                    to_account_type_id: $to_account_type_id,
                    state_id: self.customer.state_id,
                    gst_number: self.customer.gst_number,
                }
                ).then(function (response) {
                    console.log(response);
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

        self.serviceItemChanged = function () {
            self.service_item_detail = {};
        }


        //GET SBU BY BRANCH
        $scope.getSbuByBranch = function (branch_id) {
            self.extras.sbu_list = [];
            if (branch_id) {
                $.ajax({
                    url: get_sbu_url + '/' + branch_id,
                    method: "GET",
                })
                    .done(function (res) {
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
                    .fail(function (xhr) {
                        console.log(xhr);
                    });
            }
        }

        //GET SERVICE ITEM SUB CATEGORY BY CATEGORY
        $scope.getServiceItemSubCategoryByServiceItemCategory = function (category_id) {
            self.extras.sub_category_list = [];
            if (category_id) {
                $.ajax({
                    url: get_service_item_sub_category_url + '/' + category_id,
                    method: "GET",
                })
                    .done(function (res) {
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
                    .fail(function (xhr) {
                        console.log(xhr);
                    });
            }
        }

        //PERCENTAGE CALC
        $scope.percentage = function (num, per) {
            return (num / 100) * per;
        }

        //PARSEINT
        self.parseInt = function (num) {
            return parseInt(num);
        }

        // $("#qty").val(1);
        //ADD SERVICE INVOICE ITEM
        $scope.addItem = function () {
            self.add_service_action = 'add';
            self.action_title = 'Add';
            self.update_item_key = '';
            self.description = '';
            self.qty = '';
            // self.qty = 1;
            self.rate = '';
            self.sub_total = '';
            self.total = '';
            self.service_item = '';
            self.service_item_detail = '';
            self.e_invoice_uom = {};
            // console.log(' == add btn ==');
            // console.log(self.service_item_detail);
        }
        //EDIT SERVICE INVOICE ITEM
        $scope.editServiceItem = function (service_invoice_item_id, description, qty, rate, index, e_invoice_uom_id) {
            console.log(service_invoice_item_id, description, qty, rate, index, e_invoice_uom_id);
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
                    state_id: self.customer.state_id,
                    gst_number: self.customer.gst_number,
                }
                ).then(function (response) {
                    console.log(response);
                    if (response.data.success) {
                        self.service_item_detail = response.data.service_item;
                        self.service_item = response.data.service_item;
                        self.description = description;
                        self.e_invoice_uom = { 'id': e_invoice_uom_id };
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
        $scope.removeServiceItem = function (service_invoice_item_id, index) {
            self.service_invoice_item_removal_id = [];
            if (service_invoice_item_id) {
                self.service_invoice_item_removal_id.push(service_invoice_item_id);
                $('#service_invoice_item_removal_ids').val(JSON.stringify(self.service_invoice_item_removal_id));
            }
            self.service_invoice.service_invoice_items.splice(index, 1);

            //SERVICE INVOICE ITEMS TABLE CALC
            $scope.serviceInvoiceItemCalc();
        }


        $scope.calculateTcsPercentage = function () {
            if (self.service_item_detail) {
                if (self.service_item_detail.tcs_percentage) {
                    $("#doc_date").val();
                    var d = $("#doc_date").val();
                    var d1 = new Date(d.split("-").reverse().join("-")); //yyyy-mm-dd  
                    var d2 = new Date('2021-03-31'); //yyyy-mm-dd  

                    if (d1 > d2) {
                        var tcs_percentage = 1;
                    } else {
                        var tcs_percentage = self.service_item_detail.tcs_percentage;
                    }
                }
                console.log(tcs_percentage);
                return tcs_percentage;
            }
        }

        //ITEM TO INVOICE TOTAL AMOUNT CALC
        $scope.totalAmountCalc = function () {
            // console.log(self.service_invoice);
            // console.log(self.customer);
            self.sub_total = 0;
            self.total = 0;
            self.KFC_total = 0;
            self.tcs_total = 0;
            self.cess_gst_total = 0;
            self.gst_total = 0;
            if (self.qty && self.rate) {
                self.sub_total = self.qty * self.rate;
                // self.sub_total = self.rate;
                // console.log(self.sub_total);
                // console.log(self.service_item_detail);
                // console.log('in');
                if (self.service_item_detail.tax_code != null) {
                    if (self.service_item_detail.tax_code.taxes.length > 0) {
                        $(self.service_item_detail.tax_code.taxes).each(function (key, tax) {
                            tax.pivot.amount = $scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2);
                            self.gst_total += parseFloat($scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2));
                        });
                    }
                }
                //FOR TCS TAX
                // if (self.service_item_detail.tcs_percentage) {
                //     self.tcs_total = $scope.percentage(self.sub_total, self.service_item_detail.tcs_percentage).toFixed(2);
                // }
                //FOR KFC TAX
                if ($routeParams.type_id != 1060) {
                    console.log('in');
                    if (self.service_invoice.branch.primary_address.state_id && self.customer.state_id) {
                        if (self.service_invoice.branch.primary_address.state_id == 3 && self.customer.state_id == 3) {
                            if (self.customer.gst_number == null) {
                                if (self.service_item_detail.tax_code != null) {

                                    $("#doc_date").val();
                                    var d = $("#doc_date").val();
                                    var d1 = new Date(d.split("-").reverse().join("-")); //yyyy-mm-dd  
                                    var d2 = new Date('2021-07-31'); //yyyy-mm-dd  

                                    if (d1 > d2) {
                                    } else {
                                        self.KFC_total = self.sub_total / 100;
                                    }
                                    // console.log(self.sub_total);
                                    // console.log(self.KFC_total);
                                }
                            }
                        }
                    }
                }
                // else{
                //     if(self.service_invoice.branch.primary_address.state_id){
                // if(self.service_invoice.branch.primary_address.state_id == 3 && self.service_invoice.customer.primary_address.state_id == 3){
                //             self.KFC_total = self.sub_total/100;
                //         }
                //     }
                // }
                //FOR TCS TAX

                if (self.service_item_detail.tcs_percentage) {

                    $("#doc_date").val();
                    var d = $("#doc_date").val();
                    var d1 = new Date(d.split("-").reverse().join("-")); //yyyy-mm-dd  
                    var d2 = new Date('2021-03-31'); //yyyy-mm-dd  

                    var tcs_percentage = 0;
                    // if (self.sub_total >= self.tcs_limit) {
                    tcs_percentage = self.service_item_detail.tcs_percentage;
                    if (d1 > d2) {
                        tcs_percentage = 1;
                    }
                    // }

                    // self.tcs_total = $scope.percentage(self.sub_total + self.gst_total + self.KFC_total, self.service_item_detail.tcs_percentage).toFixed(2);
                    self.tcs_total = $scope.percentage(self.sub_total + self.gst_total + self.KFC_total, tcs_percentage).toFixed(2);
                }

                //FOR CESS GST TAX
                // console.log(self.service_invoice.branch.primary_address.state_id+ "state");
                if (self.service_item_detail.cess_on_gst_percentage) {
                    self.cess_gst_total = $scope.percentage(self.sub_total, self.service_item_detail.cess_on_gst_percentage).toFixed(2);
                }


                self.total = parseFloat(self.sub_total) + parseFloat(self.gst_total) + parseFloat(self.KFC_total) + parseFloat(self.tcs_total) + parseFloat(self.cess_gst_total);
                console.log(self.total);
            }
        };

        //SERVICE ITEM -TCS CALCULATION BY SURYA    
        $scope.serviceInvoiceItemTcsCal = function () {
            $("#doc_date").val();
            var d = $("#doc_date").val();
            var d1 = new Date(d.split("-").reverse().join("-")); //yyyy-mm-dd  
            var d2 = new Date('2021-03-31'); //yyyy-mm-dd
            self.tcs_total = 0;
            var overall_tcs_total = 0;
            self.service_inv_items = self.service_invoice.service_invoice_items;
            // console.log(self.service_inv_items);
            console.log('++++');
            $(self.service_invoice.service_invoice_items).each(function (key, service_invoice_item) {
                console.log(service_invoice_item);
                if (service_invoice_item.service_item) {
                    var is_tcs = service_invoice_item.service_item.is_tcs;
                    var tcs_percentage = service_invoice_item.service_item.tcs_percentage;
                } else {
                    var is_tcs = service_invoice_item.is_tcs;
                    var tcs_percentage = service_invoice_item.tcs_percentage;
                }

                if (service_invoice_item.TCS) {
                    var item_tcs_amount = parseFloat(service_invoice_item.TCS['amount']).toFixed(2);
                    console.log(item_tcs_amount);
                    var inv_total = parseFloat(service_invoice_item.total).toFixed(2) - item_tcs_amount;
                } else {
                    var inv_total = parseFloat(service_invoice_item.total).toFixed(2);
                }

                console.log(is_tcs, service_invoice_item.total, inv_total);

                if (is_tcs === 1 && tcs_percentage) {
                    // console.log(service_invoice_item);  
                    // self.invoice_amount = 0;
                    var tcs_percentage = 0;
                    self.sub_total = 0;

                    // tcs_percentage = service_invoice_item.tcs_percentage;                        
                    if (d1 > d2) {
                        tcs_percentage = 1;
                    }

                    var item_count = 0;
                    $(self.service_inv_items).each(function (item_key, service_invoice_item_cpy) {
                        console.log(service_invoice_item_cpy.code, service_invoice_item.code);
                        if (service_invoice_item_cpy.code == service_invoice_item.code) {
                            if (item_count >= 1) {
                                var sub_total = parseFloat(service_invoice_item_cpy.sub_total) + parseFloat(self.sub_total);
                                item_count++;
                                self.sub_total = sub_total;
                                console.log('if');
                                console.log(self.sub_total);
                            } else {
                                var sub_total = parseFloat(service_invoice_item_cpy.sub_total);
                                item_count++;
                                self.sub_total = sub_total;
                                console.log('else');
                                console.log(self.sub_total);
                            }
                        }
                    });

                    console.log(is_tcs, tcs_percentage, self.sub_total, self.tcs_limit, inv_total);
                    if (is_tcs === 1 && tcs_percentage && self.sub_total >= self.tcs_limit) {
                        console.log(inv_total);
                        console.log(tcs_percentage);

                        var tcs_total = $scope.percentage(inv_total, tcs_percentage).toFixed(2);
                        console.log('Tcs : ' + tcs_total);
                        overall_tcs_total += parseFloat(tcs_total).toFixed(2);

                        if (tcs_total > 0) {
                            var tcs_tax_values = {};
                            tcs_tax_values['amount'] = tcs_total;
                            tcs_tax_values['percentage'] = tcs_percentage;

                            service_invoice_item.TCS = tcs_tax_values;
                            service_invoice_item.total = parseFloat(tcs_total) + parseFloat(inv_total);
                        }
                    } else {
                        var tcs_tax_values = {};
                        tcs_tax_values['amount'] = 0;
                        tcs_tax_values['percentage'] = 0;

                        service_invoice_item.TCS = tcs_tax_values;
                        service_invoice_item.total = parseFloat(inv_total).toFixed(2);
                    }
                } else if (tcs_percentage) {
                    console.log(inv_total);
                    console.log(tcs_percentage);

                    var tcs_total = $scope.percentage(inv_total, tcs_percentage).toFixed(2);
                    console.log('Tcs : ' + tcs_total);
                    overall_tcs_total += parseInt(tcs_total);

                    if (tcs_total > 0) {
                        var tcs_tax_values = {};
                        tcs_tax_values['amount'] = tcs_total;
                        tcs_tax_values['percentage'] = tcs_percentage;

                        service_invoice_item.TCS = tcs_tax_values;
                        service_invoice_item.total = parseFloat(tcs_total) + parseFloat(inv_total);
                    }
                } else {
                    var tcs_tax_values = {};
                    tcs_tax_values['amount'] = 0;
                    tcs_tax_values['percentage'] = 0;

                    service_invoice_item.TCS = tcs_tax_values;
                    service_invoice_item.total = parseFloat(inv_total).toFixed(2);
                }
                console.log(service_invoice_item);
            });

            self.tcs_total = overall_tcs_total;

            $scope.$apply();
        }

        //SERVICE INVOICE ITEMS CALCULATION
        $scope.serviceInvoiceItemCalc = function () {
            self.table_qty = 0;
            self.table_rate = 0;
            self.table_gst_total = 0;
            self.table_sub_total = 0;
            self.table_total = 0;
            self.tax_wise_total = {};
            for (i = 0; i < self.extras.tax_list.length; i++) {
                if (typeof (self.extras.tax_list[i].name) != 'undefined') {
                    self.tax_wise_total[self.extras.tax_list[i].name + '_amount'] = 0;
                }
            };

            $(self.service_invoice.service_invoice_items).each(function (key, service_invoice_item) {
                self.table_qty += parseInt(service_invoice_item.qty);
                self.table_rate = (parseFloat(self.table_rate) + parseFloat(service_invoice_item.rate)).toFixed(2);
                st = parseFloat(service_invoice_item.sub_total).toFixed(2);

                // self.table_sub_total = (parseFloat(self.table_rate)).toFixed(2); // + parseFloat(st)).toFixed(2);
                console.log(service_invoice_item.rate);
                console.log(service_invoice_item.service_item_id);
                console.log(self.table_qty);
                self.table_sub_total += service_invoice_item.qty * service_invoice_item.rate;
                // console.log(parseFloat(self.table_sub_total));

                for (i = 0; i < self.extras.tax_list.length; i++) {
                    tax_obj = self.extras.tax_list[i];
                    if (service_invoice_item[tax_obj.name]) {
                        tax = parseFloat(service_invoice_item[tax_obj.name].amount).toFixed(2);
                        self.table_gst_total = parseFloat(self.table_gst_total) + parseFloat(tax);
                        if (typeof (self.tax_wise_total[tax_obj.name + '_amount']) == 'undefined') {
                            self.tax_wise_total[tax_obj.name + '_amount'] = 0;
                        }
                        self.tax_wise_total[tax_obj.name + '_amount'] += parseFloat(tax);
                        // self.table_sub_total = (parseFloat(self.table_sub_total) + parseFloat(tax)).toFixed(2);
                        // console.log(parseFloat(self.table_sub_total));
                    }
                };
                self.table_total = parseFloat(self.table_total) + parseFloat(service_invoice_item.total); // parseFloat(self.table_sub_total) + parseFloat(self.table_gst_total);
                if (self.table_total > 1) {
                    self.service_invoice.final_amount = Math.round(self.table_total).toFixed(2);
                } else {
                    self.service_invoice.final_amount = self.table_total.toFixed(2);
                }
                // self.service_invoice.round_off_amount = self.service_invoice.final_amount - self.table_total;
                self.service_invoice.round_off_amount = parseFloat(self.service_invoice.final_amount - self.table_total).toFixed(2);
            });
            $scope.$apply()
        }

        jQuery.validator.addMethod("mdselect_multiselect_required", function (value, element) {
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
            errorPlacement: function (error, element) {
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
            submitHandler: function (form) {
                let formData = new FormData($(service_item_form_id)[0]);
                $('#addServiceItem').button('loading');
                $.ajax({
                    url: laravel_routes['gethondaServiceItem'],
                    method: "POST",
                    data: formData,
                    processData: false,
                    contentType: false,
                })
                    .done(function (res) {
                        console.log(res);
                        if (!res.success) {
                            $('#addServiceItem').button('reset');
                            var errors = '';
                            for (var i in res.errors) {
                                errors += '<li>' + res.errors[i] + '</li>';
                            }
                            custom_noty('error', errors);
                        } else {
                            console.log(res.service_item);
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

                            $scope.$apply()
                            //SERVICE ITEMS TCS CALC
                            $scope.serviceInvoiceItemTcsCal();
                            //SERVICE INVOICE ITEMS TABLE CALC
                            $scope.serviceInvoiceItemCalc();
                            $('#addServiceItem').button('reset');
                            custom_noty('success', res.message);
                        }
                    })
                    .fail(function (xhr) {
                        $('#addServiceItem').button('reset');
                        custom_noty('error', 'Something went wrong at server');
                    });
            },
        });

        var form_id = '#form';
        var v = jQuery(form_id).validate({
            invalidHandler: function (event, validator) {
                custom_noty('error', 'Kindly check in each tab to fix errors');
            },
            errorPlacement: function (error, element) {
                if (element.hasClass("doc_date")) {
                    error.appendTo('.doc_date_error');
                }
                // else if (element.hasClass("inv_date")) {
                //     error.appendTo('.inv_date_error');
                // }
                // else if (element.hasClass("is_reverse_charge")) {
                //     error.appendTo('.reverse_charge_error');
                // }
                else {
                    error.insertAfter(element);
                }
            },
            ignore: '',
            rules: {
                'document_date': {
                    required: true,
                },
                // 'invoice_date': {
                // required: true,
                // required: function(){
                //     if($routeParams.type_id == 1060 || $routeParams.type_id == 1061){
                //         return true;
                //     }
                // },
                // },
                // 'invoice_number': {
                // required: function() {
                // if ($routeParams.type_id == 1060 || $routeParams.type_id == 1061) {
                // return true;
                // }
                // },
                // },
                // 'is_e_reverse_charge_applicable': {
                //     required: true,
                // },
                'proposal_attachments[]': {
                    // required: true,
                },
            },
            submitHandler: function (form) {
                if (self.service_invoice.final_amount == parseInt(0).toFixed(2)) {
                    custom_noty('success', 'Service Invoice total must not be 0');
                } else {
                    let formData = new FormData($(form_id)[0]);
                    $('#submit').button('loading');
                    $.ajax({
                        url: laravel_routes['saveHondaServiceInvoice'],
                        method: "POST",
                        data: formData,
                        processData: false,
                        contentType: false,
                    })
                        .done(function (res) {
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
                                 $location.path('/service-invoice-pkg/honda-service-invoice/view/' + $routeParams.type_id + '/' + res.service_invoice_id);
                                $scope.$apply()
                            }
                        })
                        .fail(function (xhr) {
                            $('#submit').button('reset');
                            custom_noty('error', 'Something went wrong at server');
                        });
                }
            },
        });
    }
});

//------------------------------------------------------------------------------------------------------------------------
//------------------------------------------------------------------------------------------------------------------------
app.component('hondaServiceInvoiceView', {
    templateUrl: honda_service_invoice_view_template_url,
    controller: function ($http, $location, HelperService, $routeParams, $rootScope, $scope, $timeout, $mdSelect, $window) {
        if ($routeParams.type_id == 1060 || $routeParams.type_id == 1061 || $routeParams.type_id == 1062) { } else {
            $location.path('/page-not-found')
            return;
        }
        $form_data_url = typeof ($routeParams.id) == 'undefined' ? honda_service_invoice_get_view_data_url + '/' + $routeParams.type_id : honda_service_invoice_get_view_data_url + '/' + $routeParams.type_id + '/' + $routeParams.id;
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;
        self.type_id = $routeParams.type_id;
        self.enable_service_item_md_change = true;
        self.ref_attachements_url_link = ref_service_invoice_attachements_url;
        if (self.type_id == 1060) {
            self.minus_value = '-';
        } else if (self.type_id == 1061 || self.type_id == 1062) {
            self.minus_value = '';
        }
        $scope.attachment_url = base_url + '/storage/app/public/honda-service-invoice-pdf';
        $scope.chola_attachment_url = base_url + '/storage/app/public/honda-service-invoice-pdf/chola-pdf';
        console.log($scope.attachment_url);
        $http.get(
            $form_data_url
        ).then(function (response) {
            if (!response.data.success) {
                new Noty({
                    type: 'error',
                    layout: 'topRight',
                    text: response.data.error,
                }).show();
                $location.path('/service-invoice-pkg/honda-service-invoice/list')
                $scope.$apply()
            }
            self.list_url = honda_service_invoice_list_url;
            self.service_invoice = response.data.service_invoice;
            self.customer = {};
            self.extras = response.data.extras;
            self.approval_status = response.data.approval_status;
            self.service_invoice_status = response.data.service_invoice_status;
            self.tcs_limit = response.data.tcs_limit;
            self.action = response.data.action;
            console.log(self.service_invoice);
            if (self.action == 'View') {
                $timeout(function () {
                    $scope.serviceInvoiceItemCalc();
                }, 1500);
                if (self.service_invoice.to_account_type_id == 1440 || self.service_invoice.to_account_type_id == 1441) { //CUSTOMER || VENDOE
                    $timeout(function () {
                        self.customer = self.service_invoice.customer;
                        if (self.service_invoice.to_account_type_id == 1440) {
                            console.log(self.customer);
                            if (self.customer.pdf_format_id == 11311) {
                                self.chola_pdf = true;
                            } else {
                                self.chola_pdf = false;
                            }
                        }
                        // $rootScope.getCustomer(self.service_invoice.customer_id);
                        if (self.service_invoice.to_account_type_id == 1441) {
                            self.chola_pdf = false;
                            $scope.vendorSelected(); //USED FOR GET FULL ADDRESS
                        }
                    }, 1200);
                }

                // ATTACHMENTS
                if (self.service_invoice.attachments.length) {
                    $(self.service_invoice.attachments).each(function (key, attachment) {
                        console.log(attachment);
                        var design = '<div class="imageuploadify-container" data-attachment_id="' + attachment.id + '" style="margin-left: 0px; margin-right: 0px;">' +
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

        $scope.vendorSelected = function () {
            // console.log('vendor');
            $('#pace').css("display", "block");
            $('#pace').addClass('pace-active');
            console.log(self.service_invoice.customer);
            if (self.service_invoice.customer || self.service_invoice.customer != null) {
                // var res = $rootScope.getCustomer(self.service_invoice.customer).then(function(res) {
                var res = $rootScope.getVendorAddress(self.service_invoice.customer).then(function (res) {
                    console.log(res);
                    if (!res.data.success) {
                        $('#pace').css("display", "none");
                        $('#pace').addClass('pace-inactive');
                        custom_noty('error', res.data.error);
                        return;
                    }
                    $('#pace').addClass('pace-inactive');
                    $('#pace').css("display", "none");
                    console.log(res.data);
                    self.customer = res.data.vendor;
                    self.service_invoice.customer.id = res.data.vendor.id;
                    if (res.data.vendor_address.length > 1) {
                        self.multiple_address = true;
                        self.single_address = false;
                        self.customer_addresses = res.data.vendor_address;
                        console.log(self.vendor_address);
                    } else {
                        self.multiple_address = false;
                        self.single_address = true;
                        self.customer.state_id = res.data.vendor_address[0].state_id;
                        self.customer.gst_number = res.data.vendor_address[0].gst_number;

                        self.customer_address = res.data.vendor_address[0];
                        console.log(self.customer + 'single');
                        if (res.data.vendor_address[0].gst_number) {
                            setTimeout(function () {
                                $scope.checkCustomerGSTIN(res.data.vendor_address[0].gst_number, self.vendor.name);
                            }, 1000);
                        }
                    }
                });
            } else {
                $('#pace').css("display", "none");
                $('#pace').addClass('pace-inactive');
                self.customer = {};
                self.customer_address = {};
                self.customer_addresses = {};
                self.service_invoice.service_invoice_items = [];
            } 
        }

        $scope.cancelIRN = function () {
            $('#cancel_irn').button('loading');
            $id = $("#service_invoice_id").val();
            $http.get(
                laravel_routes['cancelHondaIrn'], {
                params: {
                    id: $id,
                }
            }
            ).then(function (res) {
                console.log(res);
                if (!res.data.success) {
                    $('#cancel_irn').button('reset');
                    var errors = '';
                    for (var i in res.data.errors) {
                        errors += '<li>' + res.data.errors[i] + '</li>';
                    }
                    custom_noty('error', errors);
                } else {
                    custom_noty('success', res.data.message);
                    $location.path('/service-invoice-pkg/honda-service-invoice/list');
                    $scope.$apply()
                }
            });
        }

        self.qr_image_url = base_url + '/storage/app/public/honda-service-invoice/IRN_images';

        /* Tab Funtion */
        $('.btn-nxt').on("click", function () {
            $('.cndn-tabs li.active').next().children('a').trigger("click");
            tabPaneFooter();
        });
        $('.btn-prev').on("click", function () {
            $('.cndn-tabs li.active').prev().children('a').trigger("click");
            tabPaneFooter();
        });

        // $('.image_uploadify').imageuploadify();

        //PERCENTAGE CALC
        $scope.percentage = function (num, per) {
            return (num / 100) * per;
        }

        //PARSEINT
        self.parseInt = function (num) {
            // return num.toFixed(2);
            return parseInt(num);
        }

        //EDIT SERVICE INVOICE ITEM
        $scope.editServiceItem = function (service_invoice_item_id, description, qty, rate, index, e_invoice_uom_id) {
            console.log(service_invoice_item_id, description, qty, rate, index, e_invoice_uom_id);
            if (service_invoice_item_id) {
                self.enable_service_item_md_change = false;
                self.add_service_action = false;
                self.action_title = 'View';
                self.update_item_key = index;
                $http.post(
                    get_service_item_info_url, {
                    service_item_id: service_invoice_item_id,
                    field_groups: self.service_invoice.service_invoice_items[index].field_groups,
                    btn_action: 'view',
                    branch_id: self.service_invoice.branch.id,
                    customer_id: self.service_invoice.customer.id,
                    state_id: self.service_invoice.address.state_id,
                    gst_number: self.service_invoice.address.gst_number,
                }
                ).then(function (response) {
                    if (response.data.success) {
                        self.service_item_detail = response.data.service_item;
                        self.service_item = response.data.service_item;
                        self.description = description;
                        self.e_invoice_uom = { 'id': e_invoice_uom_id };
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
        $scope.totalAmountCalc = function () {
            self.sub_total = 0;
            self.total = 0;
            self.KFC_total = 0;
            self.tcs_total = 0;
            self.cess_gst_total = 0;
            self.gst_total = 0;
            if (self.qty && self.rate) {
                self.sub_total = self.qty * self.rate;
                // self.sub_total = self.rate;
                if (self.service_item_detail.tax_code != null) {
                    if (self.service_item_detail.tax_code.taxes.length > 0) {
                        $(self.service_item_detail.tax_code.taxes).each(function (key, tax) {
                            tax.pivot.amount = $scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2);
                            self.gst_total += parseFloat($scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2));
                        });
                    }
                }
                //FOR TCS TAX
                if (self.service_item_detail.tcs_percentage) {

                    $(".doc_date").val();
                    var d = $(".doc_date").val();
                    var d1 = new Date(d.split("-").reverse().join("-")); //yyyy-mm-dd  
                    var d2 = new Date('2021-03-31'); //yyyy-mm-dd  

                    var tcs_percentage = 0;
                    if (self.sub_total >= self.tcs_limit) {
                        tcs_percentage = self.service_item_detail.tcs_percentage;
                        if (d1 > d2) {
                            tcs_percentage = 1;
                        }
                    }

                    // self.tcs_total = $scope.percentage(self.sub_total + self.gst_total + self.KFC_total, self.service_item_detail.tcs_percentage).toFixed(2);
                    self.tcs_total = $scope.percentage(self.sub_total + self.gst_total + self.KFC_total, tcs_percentage).toFixed(2);
                }

                //FOR CESS GST TAX
                // console.log(self.service_invoice.branch.primary_address.state_id+ "state");
                if (self.service_item_detail.cess_on_gst_percentage) {
                    self.cess_gst_total = $scope.percentage(self.sub_total, self.service_item_detail.cess_on_gst_percentage).toFixed(2);
                }
                // FOR KFC TAX
                if ($routeParams.type_id != 1060) {
                    if (self.service_invoice.branch.primary_address.state_id && self.service_invoice.address.state_id) {
                        console.log('in');
                        if (self.service_invoice.branch.primary_address.state_id == 3 && self.service_invoice.address.state_id == 3) {
                            if (self.service_invoice.address.gst_number == null) {
                                if (self.service_item_detail.tax_code != null) {

                                    $("#doc_date").val();
                                    var d = $("#doc_date").val();
                                    var d1 = new Date(d.split("-").reverse().join("-")); //yyyy-mm-dd  
                                    var d2 = new Date('2021-07-31'); //yyyy-mm-dd  

                                    if (d1 > d2) {
                                    } else {
                                        self.KFC_total = self.sub_total / 100;
                                    }
                                    // console.log(self.sub_total);
                                    // console.log(self.KFC_total);
                                }
                            }
                        }
                    }
                }
                // else{
                //     if(self.service_invoice.branch.primary_address.state_id){
                //         if(self.service_invoice.branch.primary_address.state_id == 3 && self.service_invoice.customer.primary_address.state_id == 3){
                //             self.KFC_total = self.sub_total/100;
                //         }
                //     }
                // }
                self.total = parseFloat(self.sub_total) + parseFloat(self.gst_total) + parseFloat(self.KFC_total) + parseFloat(self.tcs_total) + parseFloat(self.cess_gst_total);
            }
        };

        //SERVICE INVOICE ITEMS CALCULATION
        $scope.serviceInvoiceItemCalc = function () {
            self.table_qty = 0;
            self.table_rate = 0;
            self.table_sub_total = 0;
            self.table_total = 0;
            self.table_gst_total = 0;
            self.tax_wise_total = {};
            for (i = 0; i < self.extras.tax_list.length; i++) {
                if (typeof (self.extras.tax_list[i].name) != 'undefined') {
                    self.tax_wise_total[self.extras.tax_list[i].name + '_amount'] = 0;
                }
            };

            $(self.service_invoice.service_invoice_items).each(function (key, service_invoice_item) {
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
                        if (typeof (self.tax_wise_total[tax_obj.name + '_amount']) == 'undefined') {
                            self.tax_wise_total[tax_obj.name + '_amount'] = 0;
                        }
                        self.tax_wise_total[tax_obj.name + '_amount'] += parseFloat(tax);
                        // self.table_sub_total = (parseFloat(self.table_sub_total) + parseFloat(tax)).toFixed(2);
                        // console.log(parseFloat(self.table_sub_total));
                    }
                };
                // console.log(parseFloat(self.table_sub_total));
                self.table_total = parseFloat(self.table_total) + parseFloat(service_invoice_item.total); // parseFloat(self.table_sub_total) + parseFloat(self.table_gst_total);
                // self.service_invoice.final_amount = Math.round(self.table_total).toFixed(2);
                if (self.table_total > 1) {
                    self.service_invoice.final_amount = Math.round(self.table_total).toFixed(2);
                } else {
                    self.service_invoice.final_amount = self.table_total.toFixed(2);
                }
                self.service_invoice.round_off_amount = parseFloat(self.service_invoice.final_amount - self.table_total).toFixed(2);
            });
            $scope.$apply()
        }

        $scope.cholaPdfDownload = function (service_invoice_id) {
            $http.get(
                laravel_routes['cholaHondaPdfCreate'], {
                params: {
                    id: service_invoice_id,
                }
            }
            ).then(function (res) {
                console.log(res);
                base_url + '/' + window.open(res.data.file_name_path, '_blank').focus();
            });

        }


        var form_id = '#form';
        var v = jQuery(form_id).validate({
            ignore: '',
            submitHandler: function (form) {
                // var submitButtonValue =  $(this.submitButton).attr("data-id");
                $('#submit').button('loading');
                $.ajax({
                    url: laravel_routes['saveHondaApprovalStatus'],
                    method: "POST",
                    data: {
                        id: $('#service_invoice_id').val(),
                        send_to_approval: $('#send_to_approval').val(),
                    },
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                    },
                })
                    .done(function (res) {
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
                            setTimeout(function () {
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
                            setTimeout(function () {
                                $noty.close();
                            }, 3000);
                            $location.path('/service-invoice-pkg/honda-service-invoice/list');
                            $scope.$apply()
                        }
                    })
                    .fail(function (xhr) {
                        $('#submit').button('reset');
                        $noty = new Noty({
                            type: 'error',
                            layout: 'topRight',
                            text: 'Something went wrong at server',
                            animation: {
                                speed: 500 // unavailable - no need
                            },
                        }).show();
                        setTimeout(function () {
                            $noty.close();
                        }, 3000);
                    });

            },
        });
    }
});