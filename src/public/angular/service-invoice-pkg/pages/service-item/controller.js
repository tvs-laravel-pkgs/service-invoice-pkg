app.component('serviceItemList', {
    templateUrl: service_item_list_template_url,
    controller: function($http, $location, HelperService, $scope, $routeParams, $rootScope, $element) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        var table_scroll;
        table_scroll = $('.page-main-content').height() - 37;
        var dataTable = $('#service-item-table').dataTable({
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
            scrollX: true,
            scrollY: table_scroll + "px",
            scrollCollapse: true,

            ajax: {
                url: laravel_routes['getServiceItemList'],
                type: "GET",
                dataType: "json",
                data: function(d) {}
            },

            columns: [
                { data: 'action', searchable: false, class: 'action' },
                { data: 'code', name: 'service_items.code', searchable: true },
                { data: 'name', name: 'service_items.name', searchable: true },
                { data: 'main_category', searchable: false },
                { data: 'sub_category', searchable: false },
                { data: 'coa_code', searchable: false },
                { data: 'sac_code', searchable: false },
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
        $("#search_box").keyup(function() { //alert(this.value);
            dataTable.fnFilter(this.value);
        });

        $(".search_clear").on("click", function() {
            $('#search_box').val('');
            $('#service-item-table').DataTable().search('').draw();
        });
        $scope.deleteServiceItem = function(id) {
            $('#service_item_id').val(id);
        }
        $scope.deleteConfirmServiceItem = function() {
            $id = $('#service_item_id').val();
            //alert($id);
            $http.get(
                service_item_delete_data_url + '/' + $id,
            ).then(function(response) {
                if (response.data.success) {
                    $noty = new Noty({
                        type: 'success',
                        layout: 'topRight',
                        text: response.data.message,
                    }).show();
                    setTimeout(function() {
                        $noty.close();
                    }, 3000);
                    $('#service-item-table').DataTable().ajax.reload(function(json) {});
                    $location.path('/service-invoice-pkg/service-item/list');
                }
            });
        }

        $rootScope.loading = false;
    }
});

//------------------------------------------------------------------------------------------------------------------------
//------------------------------------------------------------------------------------------------------------------------


app.component('serviceItemForm', {
    templateUrl: service_item_form_template_url,
    controller: function($http, $location, $location, HelperService, $routeParams, $rootScope, $scope) {
        $form_data_url = typeof($routeParams.id) == 'undefined' ? service_item_get_form_data_url : service_item_get_form_data_url + '/' + $routeParams.id;
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;

        $http.get(
            $form_data_url
        ).then(function(response) {
            /* if (!response.data.success) {
                 new Noty({
                     type: 'error',
                     layout: 'topRight',
                     text: response.data.error,
                 }).show();
                 $location.path('/service-invoice-pkg/service-invoice/list')
                 $scope.$apply()
             }*/
            console.log(response.data.service_item.field_group_ids);
            self.action = response.data.action;
            self.list_url = service_invoice_list_url;
            self.extras = response.data.extras;
            self.sub_category_list = response.data.sub_category_list;
            self.service_item = response.data.service_item;
            self.field_group_ids = response.data.service_item.field_group_ids;
            self.all_field_group_ids = response.data.service_item.all_field_group_ids;
            console.log(self.field_group_ids);
            if (self.action == 'Add') {
                //self.service_item.sub_category = [];
            }
            //self.service_item_category.sub_category = [];
            if (self.service_item.deleted_at) {
                self.switch_value = 'Inactive';
            } else {
                self.switch_value = 'Active';
            }
            //self.extras = response.data.extras;
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
        /*$('.image_uploadify').imageuploadify();*/

        $scope.onSelectedCategory = function($id) {
            //alert($id);
            $http.get(
                get_sub_category_based_category_url + '/' + $id
            ).then(function(response) {
                console.log(response.data.sub_category_list);
                self.sub_category_list = response.data.sub_category_list;
            });
        }


        self.field_group_ids = [];
        $scope.selectAllFieldGroups = function() {
            self.field_group_ids = self.all_field_group_ids;
        };

        $scope.deselectAllFieldGroups = function() {
            self.field_group_ids = [];
        };

        var form_id = '#form';
        var v = jQuery(form_id).validate({
            /* invalidHandler: function(event, validator) {
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
             },*/
            errorPlacement: function(error, element) {
                if (element.attr('name') == 'code') {
                    error.appendTo($('.item_code_error'));
                } else if (element.attr('name') == 'name') {
                    error.appendTo($('.item_name_error'));
                } else {
                    error.insertAfter(element)
                }
            },

            ignore: '',
            rules: {
                'code': {
                    required: true,
                    minlength: 3,
                    maxlength: 191,
                },
                'name': {
                    required: true,
                    minlength: 3,
                    maxlength: 191,
                },
                'main_category_id': {
                    required: true,
                },
                'sub_category_id': {
                    required: true,
                },
                'coa_code_id': {
                    required: true,
                },
                // 'sac_code_id': {
                //     required: true,
                // },
                // 'field_group_id': {
                //     required: true,
                // },
            },
            messages: {
                'code': {
                    minlength: 'Minimum of 3 charaters',
                },
                'name': {
                    minlength: 'Minimum of 3 charaters',
                }
            },
            submitHandler: function(form) {

                let formData = new FormData($(form_id)[0]);
                $('#submit').button('loading');
                $.ajax({
                        url: laravel_routes['saveServiceItem'],
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
                                type: 'error',
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
                            $location.path('/service-invoice-pkg/service-item/list');
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