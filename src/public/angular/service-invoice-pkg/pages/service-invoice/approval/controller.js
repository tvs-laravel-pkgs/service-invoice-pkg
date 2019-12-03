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
            self.approval_type_id = response.data.approval_level.approval_type_id;
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
// console.log(self.service_invoice);
            if (self.action == 'View') {
                $timeout(function() {
                    $scope.serviceInvoiceItemCalc();
                }, 1500);

                //ATTACHMENTS
                if (self.service_invoice.attachments.length) {
                    $(self.service_invoice.attachments).each(function(key, attachment) {
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

        /* Tab Funtion */
        $('.btn-nxt').on("click", function() {
            $('.cndn-tabs li.active').next().children('a').trigger("click");
            tabPaneFooter();
        });
        $('.btn-prev').on("click", function() {
            $('.cndn-tabs li.active').prev().children('a').trigger("click");
            tabPaneFooter();
        });

        $('.image_uploadify').imageuploadify();

        //PERCENTAGE CALC
        $scope.percentage = function(num, per) {
            return (num / 100) * per;
        }

        //PARSEINT
        self.parseInt = function(num) {
            return parseInt(num);
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
                            // status_id : submitButtonValue,
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
                            $location.path('/service-invoice-pkg/cn-dn/approval/approval-level/'+ $routeParams.approval_type_id +'/list/');
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