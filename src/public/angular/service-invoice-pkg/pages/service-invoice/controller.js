app.component('serviceInvoiceList', {
    templateUrl: service_invoice_list_template_url,
    controller: function($http, $location, HelperService, $scope, $routeParams, $rootScope, $element) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
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
    controller: function($http, $location, $location, HelperService, $routeParams, $rootScope, $scope) {
        $form_data_url = typeof($routeParams.id) == 'undefined' ? service_invoice_get_form_data_url : service_invoice_get_form_data_url + '/' + $routeParams.id;
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;

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
                $scope.getServiceItemSubCategoryByServiceItemCategory(self.service_invoice.service_item_sub_category.category_id);
                $scope.serviceInvoiceItemCalc();
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

        /* Image Uploadify Funtion */
        $('.image_uploadify').imageuploadify();

        $('.docDatePicker').bootstrapDP({
            endDate: 'today',
            todayHighlight: true
        });

        //OBJECT EMPTY CHECK
        $scope.isObjectEmpty = function(objvar) {
            if (objvar) {
                return Object.keys(objvar).length === 0;
            }
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
                        });
                    //reject(response);
                });
            } else {
                return [];
            }
        }

        //GET SERVICE ITEM DETAILS
        self.getServiceItemDetails = function() {
            console.log(' == md change ==');
            console.log(self.service_item);
            if (!self.service_item) {
                return
            }
            $http.post(
                get_service_item_info_url, {
                    service_item_id: self.service_item.id,
                }
            ).then(function(response) {
                if (response.data.success) {
                    self.service_item_detail = response.data.service_item;

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

        self.serviceItemChanged = function() {
            self.service_item_detail = {};
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
        }

        //EDIT SERVICE INVOICE ITEM
        $scope.editServiceItem = function(service_invoice_item_id, description, qty, rate, index) {
            if (service_invoice_item_id) {
                self.add_service_action = false;
                self.action_title = 'Update';
                self.update_item_key = index;
                $http.post(
                    get_service_item_info_url, {
                        service_item_id: service_invoice_item_id,
                    }
                ).then(function(response) {
                    if (response.data.success) {
                        self.service_item_detail = response.data.service_item;
                        self.service_item = response.data.service_item;
                        self.description = description;
                        self.qty = qty;
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
        self.removeServiceItem = function(service_invoice_item_id, index) {
            self.service_invoice_item_removal_id = [];
            if (service_invoice_item_id) {
                self.service_invoice_item_removal_id.push(service_invoice_item_id);
                $('#service_invoice_item_removal_ids').val(JSON.stringify(self.service_invoice_item_removal_id));
            }
            self.service_invoice.service_invoice_items.splice(index, 1);
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
                        tax.pivot.amount = parseInt($scope.percentage(self.sub_total, parseInt(tax.pivot.percentage)));
                        self.gst_total += parseInt($scope.percentage(self.sub_total, parseInt(tax.pivot.percentage)));
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
                self.table_rate += parseInt(service_invoice_item.rate);
                self.table_sub_total += parseInt(service_invoice_item.sub_total);
                $(self.extras.tax_list).each(function(key, tax) {
                    self.table_gst_total += parseInt(service_invoice_item[tax.name].amount);
                    self[tax.name + '_amount'] += parseInt(service_invoice_item[tax.name].amount);
                });
            });
            self.table_total = self.table_sub_total + self.table_gst_total;
            $scope.$apply()
        }

        var service_item_form_id = '#service-invoice-item-form';
        var service_v = jQuery(service_item_form_id).validate({
            errorPlacement: function(error, element) {
                error.insertAfter(element)
            },
            ignore: '',
            rules: {
                'description': {
                    required: true,
                },
                'qty': {
                    required: true,
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
                            $('#modal-cn-addnew').modal('toggle');

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
                error.insertAfter(element)
            },
            ignore: '',
            rules: {
                'document_date': {
                    required: true,
                },
                'proposal_attachments[]': {
                    required: true,
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