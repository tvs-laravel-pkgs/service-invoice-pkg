app.component('serviceInvoiceList', {
    templateUrl: service_invoice_list_template_url,
    controller: function($http, $location, HelperService, $scope, $routeParams, $rootScope, $element) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        var table_scroll;
        table_scroll = $('.page-main-content').height() - 37;
        var dataTable = $('#service-invoice-table').dataTable({
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
                data: function(d) {}
            },

            columns: [
                { data: 'child_checkbox', searchable: false },
                { data: 'action', searchable: false, class: 'action' },
                { data: 'invoice_date', searchable: false },
                { data: 'number', name: 'service_invoices.number', searchable: true },
                { data: 'type_name', name: 'configs.name', searchable: true },
                { data: 'branch', name: 'outlets.code', searchable: true },
                { data: 'sbu', name: 'sbus.name', searchable: true },
                { data: 'category', name: 'service_item_categories.name', searchable: true },
                { data: 'sub_category', name: 'service_item_sub_categories.name', searchable: true },
                { data: 'customer_code', name: 'customers.code', searchable: true },
                { data: 'customer_name', name: 'customers.name', searchable: true },
                { data: 'invoice_amount', searchable: false },
            ],
            rowCallback: function(row, data) {
                $(row).addClass('highlight-row');
            },
            infoCallback: function(settings, start, end, max, total, pre) {
                $('#table_info').html(total)
                $('.foot_info').html('Showing ' + start + ' to ' + end + ' of ' + max + ' entries')
            },
        });
        $('.dataTables_length select').select2();
        $("#search").keyup(function() { //alert(this.value);
            dataTable.fnFilter(this.value);
        });

        $(".search_clear").on("click", function() {
            $('#search').val('');
            $('#service-invoice-table').DataTable().search('').draw();
        });

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
            self.action = response.data.action;

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
        $('.image_uploadify').imageuploadify();

        $('.docDatePicker').bootstrapDP({
            endDate: 'today',
            todayHighlight: true
        });

        //ATTACHMENT REMOVE
        $(document).on('click', ".main-wrap .imageuploadify-container .imageuploadify-btn-remove button", function() {
            var attachment_id = $(this).parent().parent().data('attachment_id');
            attachment_removal_ids.push(attachment_id);
            $('#attachment_removal_ids').val(JSON.stringify(attachment_removal_ids));
            $(this).parent().parent().remove();
        });

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
                    $noty = new Noty({
                        type: 'error',
                        layout: 'topRight',
                        text: response.data.error,
                        animation: {
                            speed: 500 // unavailable - no need
                        },
                    }).show();
                    setTimeout(function() {
                        $noty.close();
                    }, 5000);
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
        self.searchServiceItem = function(query) {
            if (query) {
                return new Promise(function(resolve, reject) {
                    $http
                        .post(
                            search_service_item_url, {
                                key: query,
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
                        $noty = new Noty({
                            type: 'error',
                            layout: 'topRight',
                            text: response.data.error,
                            animation: {
                                speed: 500 // unavailable - no need
                            },
                        }).show();
                        setTimeout(function() {
                            $noty.close();
                        }, 5000);
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

        //ADD SERVICE INVOICE ITEM
        $scope.addItem = function() {
            self.add_service_action = 'add';
            self.action_title = 'Add';
            self.update_item_key = '';
            self.description = '';
            self.qty = '';
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
                        $noty = new Noty({
                            type: 'error',
                            layout: 'topRight',
                            text: response.data.error,
                            animation: {
                                speed: 500 // unavailable - no need
                            },
                        }).show();
                        setTimeout(function() {
                            $noty.close();
                        }, 5000);
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
                if (self.service_item_detail.tax_code.taxes.length > 0) {
                    $(self.service_item_detail.tax_code.taxes).each(function(key, tax) {
                        tax.pivot.amount = Math.round($scope.percentage(self.sub_total, tax.pivot.percentage));
                        self.gst_total += Math.round($scope.percentage(self.sub_total, tax.pivot.percentage));
                    });
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
            $(self.extras.tax_list).each(function(key, tax) {
                self[tax.name + '_amount'] = 0;
            });

            $(self.service_invoice.service_invoice_items).each(function(key, service_invoice_item) {
                self.table_qty += parseInt(service_invoice_item.qty);
                self.table_rate += Math.round(service_invoice_item.rate);
                self.table_sub_total += Math.round(service_invoice_item.sub_total);
                $(self.extras.tax_list).each(function(key, tax) {
                    self.table_gst_total += (service_invoice_item[tax.name] ? Math.round(service_invoice_item[tax.name].amount) : 0);
                    self[tax.name + '_amount'] += (service_invoice_item[tax.name] ? Math.round(service_invoice_item[tax.name].amount) : 0);
                });
            });
            self.table_total = self.table_sub_total + self.table_gst_total;
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
                            }, 5000);
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
                            }, 5000);
                        }
                    })
                    .fail(function(xhr) {
                        $('#addServiceItem').button('reset');
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
                        }, 5000);
                    });
            },
        });



        var form_id = '#form';
        var v = jQuery(form_id).validate({
            invalidHandler: function(event, validator) {
                $noty = new Noty({
                    type: 'error',
                    layout: 'topRight',
                    text: 'Kindly check in each tab to fix errors',
                    animation: {
                        speed: 500 // unavailable - no need
                    },
                }).show();
                setTimeout(function() {
                    $noty.close();
                }, 5000);
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
                            }, 5000);
                        } else {
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
                            }, 5000);
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
                        }, 5000);
                    });
            },
        });
    }
});