app.component('serviceItemCategoryList', {
    templateUrl: service_item_category_list_template_url,
    controller: function($http, $location, HelperService, $scope, $routeParams, $rootScope, $element) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        var table_scroll;
        table_scroll = $('.page-main-content').height() - 37;
        var dataTable = $('#service-item-category-table').dataTable({
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
            scrollY: table_scroll + "px",
            scrollCollapse: true,

            ajax: {
                url: laravel_routes['getServiceItemCategoryList'],
                type: "GET",
                dataType: "json",
                data: function(d) {}
            },

            columns: [
                { data: 'action', searchable: false, class: 'action' },
                { data: 'name', name: 'service_item_categories.name', searchable: true },
                { data: 'sub_category', searchable: false },
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
            $('#service-item-category-table').DataTable().search('').draw();
        });
        $scope.deleteServiceItemCategory = function(id) {
            $('#service_item_category_id').val(id);
        }
        $scope.deleteConfirmServiceItemCategory = function() {
            $id = $('#service_item_category_id').val();
            //alert($id);
            $http.get(
                service_item_category_delete_data_url + '/' + $id,
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
                    $('#service-item-category-table').DataTable().ajax.reload(function(json) {});
                    $location.path('/service-invoice-pkg/service-item-category/list');
                }
            });
        }

        $rootScope.loading = false;
    }
});

//------------------------------------------------------------------------------------------------------------------------
//------------------------------------------------------------------------------------------------------------------------


app.component('serviceItemCategoryForm', {
    templateUrl: service_item_category_form_template_url,
    controller: function($http, $location, $location, HelperService, $routeParams, $rootScope, $scope) {
        $form_data_url = typeof($routeParams.id) == 'undefined' ? service_item_category_get_form_data_url : service_item_category_get_form_data_url + '/' + $routeParams.id;
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
            self.action = response.data.action;
            self.list_url = service_invoice_list_url;
            self.service_item_category = response.data.service_item_category;
            if (self.action == 'Add') {
                self.service_item_category.sub_category = [];
            }
            //self.service_item_category.sub_category = [];
            console.log(self.service_item_category.sub_category);
            if (self.service_item_category.deleted_at) {
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
        //$('.image_uploadify').imageuploadify();

        // FIELDS
        $scope.addNewSubCategory = function() {
            self.service_item_category.sub_category.push({
                name: '',
                switch_value: 'Active',
                additional_image: 'null',
            });
        }
        self.sub_category_removal_id = [];
        //REMOVE FIELD
        $scope.removeSubCategory = function(index, sub_category_id) {
            //alert(sub_category_id);
            if (sub_category_id) {
                self.sub_category_removal_id.push(sub_category_id);
                $('#sub_category_removal_id').val(JSON.stringify(self.sub_category_removal_id));
            }

            self.service_item_category.sub_category.splice(index, 1);
        }
        $.validator.messages.minlength = 'Minimum of 3 charaters';
        jQuery.validator.addClassRules("sub_category_name", {
            required: true,
            minlength: 3
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
            /*errorPlacement: function(error, element) {
                if (element.attr('name') == 'name') {
                    error.appendTo($('.category_name_error'));
                }
            },*/
            ignore: '',
            rules: {
                'name': {
                    required: true,
                    minlength: 3,
                    maxlength: 191,
                },
            },
            messages: {
                'name': {
                    minlength: 'Minimum of 3 charaters',
                }
            },
            submitHandler: function(form) {

                let formData = new FormData($(form_id)[0]);
                $('#submit').button('loading');
                $.ajax({
                        url: laravel_routes['saveServiceItemCategory'],
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
                            $location.path('/service-invoice-pkg/service-item-category/list');
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