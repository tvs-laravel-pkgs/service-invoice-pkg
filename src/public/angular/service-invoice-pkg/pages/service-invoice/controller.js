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
            self.extras = response.data.extras;
            self.action = response.data.action;
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

        // FIELDS
        self.addNewFields = function() {
            self.field_group.fields.push({
                id: '',
                is_required_status: 'Yes',
            });
        }
        //REMOVE FIELD
        self.removeField = function(index) {
            self.field_group.fields.splice(index, 1);
        }

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
                'name': {
                    required: true,
                    maxlength: 191,
                    minlength: 3,
                },
            },
            submitHandler: function(form) {

                let formData = new FormData($(form_id)[0]);
                $('#submit').button('loading');
                $.ajax({
                        url: laravel_routes['saveFieldGroup'],
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
                            $location.path('/attribute-pkg/field-group/list' + '/' + $routeParams.category_id);
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