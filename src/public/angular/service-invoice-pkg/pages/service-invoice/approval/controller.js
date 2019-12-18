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
        setTimeout(function() {
        // console.log(self.approval_type_id);
        var table_scroll;
        table_scroll = $('.page-main-content').height() - 37;
        var dataTable = $('#cn-dn-approval-table').dataTable({
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
                url: laravel_routes['getServiceInvoiceApprovalList'],
                type: "GET",
                dataType: "json",
                data: function(d) {
                    d.approval_status_id = self.approval_type_id;
                }
            },

            columns: [
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
                { data: 'status', name: 'approval_type_statuses.status', searchable: false },
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
        }, 1000);
        $("#search").keyup(function() { //alert(this.value);
            dataTable.fnFilter(this.value);
        });

        $(".search_clear").on("click", function() {
            $('#search').val('');
            $('#cn-dn-approval-table').DataTable().search('').draw();
        });

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
        $form_data_url = cn_dn_aprroval_view_data_url + '/' + $routeParams.approval_type_id+ '/' + $routeParams.type_id + '/' + $routeParams.id;
        //alert($form_data_url);
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;
        self.type_id = $routeParams.type_id;
        self.approval_type_id = $routeParams.approval_type_id;
        self.enable_service_item_md_change = true;
        self.ref_attachements_url_link = ref_attachements_url;
        $http.get(
            $form_data_url
        ).then(function(response) {
            if (!response.data.success) {
                new Noty({
                    type: 'error',
                    layout: 'topRight',
                    text: response.data.error,
                }).show();
                $location.path('/service-invoice-pkg/cn-dn/approval/approval-level/'+ $routeParams.approval_type_id +'/list/')
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
                if (self.service_item_detail.tax_code.taxes.length > 0) {
                    $(self.service_item_detail.tax_code.taxes).each(function(key, tax) {
                        tax.pivot.amount = $scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2);
                        self.gst_total += parseFloat($scope.percentage(self.sub_total, tax.pivot.percentage).toFixed(2));
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
                var submitButtonId =  $(this.submitButton).attr("id");
                var comment_value = $('#comments').val();
                if(submitButtonId == 'reject' && comment_value == '') {
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
                            id : $('#id').val(),
                            approval_type_id : $('#approval_type_id').val(),
                            comments : $('#comments').val(),
                            status_name : submitButtonId,
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
                            if(res.message == 'Rejected') {
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
                                $location.path('/service-invoice-pkg/cn-dn/approval/approval-level/'+ $routeParams.approval_type_id +'/list/');
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