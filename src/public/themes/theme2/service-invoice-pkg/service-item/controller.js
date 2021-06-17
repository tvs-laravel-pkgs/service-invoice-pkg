app.component('serviceItemList', {
    templateUrl: service_item_list_template_url,
    controller: function($http, $location, HelperService, $scope, $routeParams, $rootScope, $element, $mdSelect) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.export = self.hasPermission('export-service-item');
        $http.get(
            get_service_item_filter_url
        ).then(function(response) {
            self.extras = response.data.extras;
            $rootScope.loading = false;
            // console.log(self.extras);
        });

        var dataTable
        setTimeout(function() {
            var table_scroll;
            table_scroll = $('.page-main-content').height() - 37;
            dataTable = $('#service-item-table').DataTable({
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
                        $('#search_box').val(state_save_val.search.search);
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
                    data: function(d) {
                        d.item_code = $('#item_code').val();
                        d.item_name = $('#item_name').val();
                        d.main_category_id = $('#main_category_id').val();
                        d.sub_category_id = $('#sub_category_id').val();
                        d.coa_code_id = $('#coa_code_id').val();
                        d.sac_code_id = $('#sac_code_id').val();
                        d.tcs_percentage = $('#tcs_percentage').val();
                    },
                },

                columns: [
                    { data: 'action', searchable: false, class: 'action' },
                    { data: 'code', name: 'service_items.code', searchable: true },
                    { data: 'name', name: 'service_items.name', searchable: true },
                    { data: 'main_category', name: 'service_item_categories.name', searchable: true },
                    { data: 'sub_category', name: 'service_item_sub_categories.name', searchable: true },
                    { data: 'coa_code', name: 'coa_codes.code', searchable: true },
                    { data: 'sac_code', name: 'tax_codes.code', searchable: true },
                    { data: 'tcs_percentage', name: 'service_items.tcs_percentage', searchable: true },
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
        }, 900);
        $('.modal').bind('click', function(event) {
            if ($('.md-select-menu-container').hasClass('md-active')) {
                $mdSelect.hide();
            }
        });
        $('.refresh_table').on("click", function() {
            $('#service-item-table').DataTable().ajax.reload();
        });
        $("#search_box").keyup(function() { //alert(this.value);
            dataTable
                .search(this.value)
                .draw();
        });

        $(".search_clear").on("click", function() {
            $('#search_box').val('');
            $('#service-item-table').DataTable().search('').draw();
        });
        $('#item_code').keyup(function() {
            setTimeout(function() {
                dataTable.draw();
            }, 900);
        });
        $('#item_name').keyup(function() {
            setTimeout(function() {
                dataTable.draw();
            }, 900);
        });
        $('#tcs_percentage').keyup(function() {
            setTimeout(function() {
                dataTable.draw();
            }, 900);
        });
        $scope.onSelectedCategory = function($id) {
            //alert($id);
            self.extras.sub_category_list = [];
            if ($id == "") {
                $('#sub_category_id').val('');
                $('#main_category_id').val('');
                dataTable.draw();
            } else {
                $('#main_category_id').val($id);
                dataTable.draw();
                $http.get(
                    get_sub_category_based_category_url + '/' + $id
                ).then(function(response) {
                    //console.log(response.data.sub_category_list);
                    self.extras.sub_category_list = response.data.sub_category_list;
                });
            }

        }
        $scope.getSubCategory = function(selected_sub_category_id) {
            setTimeout(function() {
                $('#sub_category_id').val(selected_sub_category_id);
                dataTable.draw();
            }, 900);
        }
        //SEARCH COA CODE
        self.searchCoaCodeFilter = function(query) {
            if (query) {
                return new Promise(function(resolve, reject) {
                    $http
                        .post(
                            search_coa_code_url, {
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
        $scope.getCoaCodeDetails = function(selected_coa_code_id) {
            setTimeout(function() {
                $('#coa_code_id').val(selected_coa_code_id);
                dataTable.draw();
            }, 900);
        }
        //SEARCH SAC CODE
        self.searchSacCodeFilter = function(query) {
            if (query) {
                return new Promise(function(resolve, reject) {
                    $http
                        .post(
                            search_sac_code_url, {
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
        $scope.getSacCodeDetails = function(selected_sac_code_id) {
            setTimeout(function() {
                $('#sac_code_id').val(selected_sac_code_id);
                dataTable.draw();
            }, 900);
        }

        $scope.reset_filter = function() {
            $('#item_code').val('');
            $('#item_name').val('');
            $('#main_category_id').val('');
            $('#sub_category_id').val('');
            $('#coa_code_id').val('');
            $('#sac_code_id').val('');
            $('#tcs_percentage').val('');
            dataTable.draw();
        }

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

        //EXPORT
        self.exportServiceItem = laravel_routes['exportServiceInvoiceItemsToExcel'];
        self.csrf_token = $('meta[name="csrf-token"]').attr('content');
        var filter_form_id = '#filter-form';
        var filter_form_v = jQuery(filter_form_id).validate({
            // errorPlacement: function(error, element) {
            //     if (element.hasClass("dynamic_date")) {
            //         error.insertAfter(element.parent("div"));
            //     } else {
            //         error.insertAfter(element);
            //     }
            // },
            ignore: '',
            rules: {  
            },
            submitHandler: function(form) {
                form.submit();
            },
        });

        $rootScope.loading = false;
    }
});

//------------------------------------------------------------------------------------------------------------------------
//------------------------------------------------------------------------------------------------------------------------


app.component('serviceItemForm', {
    templateUrl: service_item_form_template_url,
    controller: function($http, $location, $location, HelperService, $routeParams, $rootScope, $scope, $element) {
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
            // console.log(response.data.service_item.field_group_ids);
            self.action = response.data.action;
            self.list_url = service_invoice_list_url;
            self.extras = response.data.extras;
            self.sub_category_list = response.data.sub_category_list;
            self.service_item = response.data.service_item;
            self.field_group_ids = response.data.service_item.field_group_ids;
            self.all_field_group_ids = response.data.service_item.all_field_group_ids;
            console.log(self.service_item);
            if (self.action == 'Add') {
                //self.service_item.sub_category = [];
            }
            //self.service_item_category.sub_category = [];
            if (self.service_item.deleted_at) {
                self.switch_value = 'Inactive';
            } else {
                self.switch_value = 'Active';
            }

            if (self.service_item.is_tcs == 1) {
                self.tcs_value = 'Yes';
            } else {
                self.tcs_value = 'No';
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

        //SEARCH MD_SELECT
        $element.find('input').on('keydown', function(ev) {
            ev.stopPropagation();
        });
        $scope.clearSearchTerm = function() {
            $scope.searchMainCategory = '';
            $scope.searchSubCategory = '';
            $scope.searchCoa = '';
            $scope.searchSac = '';
        };

        //SEARCH COA CODE
        self.searchCoaCode = function(query) {
            if (query) {
                return new Promise(function(resolve, reject) {
                    $http
                        .post(
                            search_coa_code_url, {
                                key: query,
                            }
                        )
                        .then(function(response) {
                            resolve(response.data);
                        });
                });
            } else {
                return [];
            }
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
                'default_reference': {
                    minlength: 3,
                    maxlength: 255,
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