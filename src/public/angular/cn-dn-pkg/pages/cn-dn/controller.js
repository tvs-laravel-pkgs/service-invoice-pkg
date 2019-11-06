app.component('outletList', {
    templateUrl: outlet_list_template_url,
    controller: function($http, $location, HelperService, $scope, $routeParams, $rootScope, $element) {
        $http.get(
            get_outlet_filter_url
        ).then(function(response) {


            self.country_list = response.data.country_list;


        });
        $scope.loading = true;
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        var dataTable = $('#outlet-table').DataTable({
            "dom": dom_structure_2,
            "language": {
                "search": "",
                "searchPlaceholder": "Search",
                "lengthMenu": "Rows Per Page _MENU_",
                "paginate": {
                    "next": '<i class="icon ion-ios-arrow-forward"></i>',
                    "previous": '<i class="icon ion-ios-arrow-back"></i>'
                },
            },
            pageLength: 10,
            processing: true,
            serverSide: true,
            paging: true,
            ordering: false,
            ajax: {
                url: laravel_routes['getlistOutlets'],
                type: "GET",
                dataType: "json",
                data: function(d) {
                    // alert();
                    d.outlet_code = $('.outlet_code').val();
                    d.outlet_name = $('.outlet_name').val();
                    d.country_id = $('.country_id').val();
                    d.state_id = $('.state_id').val();
                    d.region_id = $('.region_id').val();
                },
            },

            columns: [

                { data: 'action', class: 'action', name: 'action', searchable: false },
                { data: 'outlet_code', name: 'outlets.code' },
                { data: 'outlet_name', name: 'outlets.name' },
                { data: 'region_name' },
                { data: 'state_name', name: 'st.name' },
                { data: 'country_name', name: 'countries.name' },
                { data: 'status', searchable: false },
            ],
            "infoCallback": function(settings, start, end, max, total, pre) {
                $('#table_info').html('(' + max + ')')
            },
            rowCallback: function(row, data) {
                $(row).addClass('highlight-row');
            }
        });
        $('.dataTables_length select').select2();

        $('.title-block').html('<h1 class="title">Outlets<span class="badge badge-secondary" id="table_info">0</span></h1>' +
            '<p class="subtitle">Masters / Outlets</p>'
        );
        $('li').removeClass('active');
        $('.master_link').addClass('active').trigger('click');
        $('.page-header-content-left .button-block').html(
            '<button class="btn btn-bordered" data-toggle="modal" data-target="#filter">' +
            '<i class="icon ion-md-funnel"></i>Filter' +
            '</button>'
        );

        $('.page-header-content-right .button-block').html(
            '<a href="#!/master/outlets/add" type="button" class="btn btn-primary">' +
            'Add New' + '</a>'
        );


        var dataTable = $('#outlet-table').dataTable();

        $scope.get_outlet_code = function(query) {
            dataTable.fnFilter();
        }

        $scope.get_outlet_name = function(query) {
            dataTable.fnFilter();
        }
        $scope.onSelectedCountry = function(country_id_selected) {
            $('.country_id').val(country_id_selected);
            dataTable.fnFilter();
        }

        $scope.onSelectedState = function(state_id_selected) {
            $('.state_id').val(state_id_selected);
            dataTable.fnFilter();
        }

        $scope.onSelectedRegion = function(region_id_selected) {
            $('.region_id').val(region_id_selected);
            dataTable.fnFilter();
        }

        $scope.get_country_base_state = function(id) {

            // alert(id);
            $http.get(
                outlet_get_state_filter_list + '/' + id
            ).then(function(response) {
                self.state_list = response.data.state_list;

            });
        }

        $scope.get_state_base_region = function(id) {

            // alert("state"+id);
            $http.get(
                outlet_get_region_filter_list + '/' + id
            ).then(function(response) {
                // console.log(response.data.region_filter_list);
                self.region_filter_list = response.data.region_filter_list;

            });
        }

        $scope.calldeleteConfirm = function($id) {
            // alert($id);
            $('#delete_id').val($id);
        }
        $scope.deleteConfirm = function() {


            $id = $('#delete_id').val();
            $http.get(
                delete_outlet_url + '/' + $id,
            ).then(function(response) {
                if (response.data.success) {

                    new Noty({
                        type: 'success',
                        layout: 'topRight',
                        text: 'Outlet Deleted Successfully',
                    }).show();

                    $('.outlet_list').DataTable().ajax.reload(function(json) {});
                    $location.path('master/outlets');

                }
            });
        }

        $rootScope.loading = false;

    }
});

//Outlet view

app.component('outletView', {

    templateUrl: outlet_view_template_get_url,

    controller: function($http, $location, $routeParams, $rootScope, HelperService) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        $http.get(
            get_outlet_view_value + '/' + $routeParams.id
        ).then(function(response) {

            // self.employee = response.data.employee;
            // self.reporting_to = response.data.reporting_to;
            // $rootScope.loading = false;
            // console.log(self.employee);
            self.outlet_view = response.data.outlet_view_values;
            self.lob = response.data.lob;
            self.sbu = response.data.sbu;
            self.business = response.data.business;
            self.status = response.data.status;
            self.oulet_id = response.data.oulet_id;

            // console.log(self.outlet_view);

        });

    }
});


//OUTLET Form

app.component('outletForm', {
    templateUrl: outlet_form_template_get_url,

    controller: function($http, $location, HelperService, $scope, $routeParams, $window, $element, $rootScope) {
        get_form_data_url = typeof($routeParams.id) == 'undefined' ? outlet_get_edit_value : outlet_get_edit_value + '/' + $routeParams.id;
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;
        $http.get(
            get_form_data_url
        ).then(function(response) {

            self.status = response.data.status;
            // console.log( response.data.outlet.status);
            self.country = response.data.country;
            self.central_cashier = response.data.central_cashier;
            self.bussiness = response.data.bussiness_data;
            self.user = response.data.user;
            self.company_id = response.data.company_id;
            self.lob = response.data.lob;
            self.sbus_data = response.data.sbu;
            self.bussiness_data = response.data.bussiness_data;
            self.address = response.data.address;
            self.outlet = response.data.outlet;
            self.lob_outlet = response.data.lob_outlet;
            self.outlet_sbu = response.data.outlet_sbu;
            self.checked_business = response.data.checked_business;
            self.action = response.data.action;
            lob_outlet = response.data.lob_outlet;
            outlet_sbu = response.data.outlet_sbu;
            checked_business = response.data.checked_business;
            self.business_selected = response.data.business_selected;
            // console.log(self.business_selected);
            // console.log(self.lob_outlet);
            if (self.action == 'Edit') {
                load_sbu(self.lob_outlet);
                load_business(self.checked_business, response.data.outlet.id);
                $scope.get_country_id(response.data.address.country_id);
                $scope.get_state(response.data.address.state_id);

            }
            $rootScope.loading = false;
        });

        $scope.valueChecked = function(id) {

            var value = lob_outlet.indexOf(id);
            return value;
        }
        $scope.sbuChecked = function(id) {

            var value = outlet_sbu.indexOf(id);
            return value;
        }

        $scope.businessChecked = function(id) {

            var value = checked_business.indexOf(id);
            return value;
        }

        function load_sbu(ids) {
            $http.get(

                outlet_get_edit_sbu_data_list + '/' + ids
            ).then(function(response) {
                // console.log("rady");
                $('.main_sbu').prop('disabled', false);
                response.data.sbus.forEach(function(sbus) {
                    self.sbus_data.push({
                        "name": sbus.name,
                        "id": sbus.id
                    });
                });


            });

        }

        function load_business(ids, outlet_id) {
            // console.log(ids,outlet_id);
            $http.get(

                outlet_get_edit_business_data_list + '/' + ids + '/' + outlet_id
            ).then(function(response) {
                // console.log(response.data.business_outlet);
                response.data.business_outlet.forEach(function(business_outlet) {
                    $("#outlet_code_" + business_outlet.business_id).val(business_outlet.outlet_code);

                    $("#outlet_code_" + business_outlet.business_id).prop('readonly', false);
                    $("#outlet_name_" + business_outlet.business_id).val(business_outlet.outlet_name);

                    $("#outlet_name_" + business_outlet.business_id).prop('readonly', false);
                });


            });

        }


        $scope.getSbuData = function(id) {

            if (event.target.checked == true) {

                $('.main_sbu').prop('disabled', false);

                $http.get(

                    outlet_get_sbu_data_list + '/' + id
                ).then(function(response) {

                    response.data.sbus.forEach(function(sbus) {
                        self.sbus_data.push({
                            "name": sbus.name,
                            "id": sbus.id
                        });
                    });


                });

            } else {
                $('.main_sbu').prop('disabled', true);
                if ($('.lob_check:checked').length > 0) {
                    self.sbus_data = [];
                    $.each($('.lob_check:checked'), function() {
                        $scope.pushing_new_item($(this).val())
                    });
                } else {
                    $('.lob_check').prop('checked', false);
                    $('#main_sbu_table tbody tr').html('');
                }
            }
        }

        $scope.pushing_new_item = function(id) {
            $http.get(
                outlet_get_sbu_data_list + '/' + id
            ).then(function(response) {
                response.data.sbus.forEach(function(sbus) {
                    if (id == sbus.lob_id) {
                        self.sbus_data.push({
                            "name": sbus.name,
                            "id": sbus.id
                        });
                    }
                });
            });
        }



        $scope.getBusinessData = function(id) {

            if (event.target.checked == true) {
                $http.get(
                    outlet_get_business_data_list + '/' + id
                ).then(function(response) {

                    var business_id = response.data.business_outlet.business_id;
                    // $("#outlet_code_"+business_id).val(response.data.business_outlet.outlet_code);
                    // $("#outlet_code_"+business_id).prop('readonly', false);
                });
            } else {

                $http.get(
                    outlet_get_business_data_list + '/' + id
                ).then(function(response) {

                    var business_id = response.data.business_outlet.business_id;
                    // $("#outlet_code_"+business_id).prop('readonly', true);
                });



            }
        }
        $('.main_sbu').prop('disabled', true);
        $('.main_lob').on('click', function() {
            if (event.target.checked == true) {
                $('.main_sbu').prop('disabled', false);
                $('#main_sbu_table tbody tr').html('');
                $('.lob_check').prop('checked', true);
                $.each($(".lob_check:checked"), function() {
                    $scope.getSbuData($(this).val());
                });

            } else {
                $('.main_sbu').prop('disabled', true);
                $('.lob_check').prop('checked', false);
                $('#main_sbu_table tbody tr').html('');
            }
        });

        $(document).on("click", ".main_sbu", function() {

            if (event.target.checked == true) {
                $('#main_sbu_table').find('input[name="sbu_id[]"]').prop('checked', true).trigger("change");
            } else {
                $('#main_sbu_table').find('input[name="sbu_id[]"]').prop('checked', false).trigger("change");

            }
        });

        $('#main_business').on('click', function() {

            if (event.target.checked == true) {
                $('.business_sub_check').prop('checked', true);
                $.each($('.business_sub_check:checked'), function() {
                    $scope.getcodeonBusiness($(this).val());
                });
            } else {
                $('.business_sub_check').prop('checked', false);
                $.each($('.business_sub_check'), function() {
                    $scope.getcodeonBusiness($(this).val());
                });
            }
        });
        $scope.getcodeonBusiness = function(id) {
            if (event.target.checked == true) {
                $("#outlet_code_" + id).prop('readonly', false);
                $(".validation_for_code_" + id).prop('required', true);
                $("#outlet_name_" + id).prop('readonly', false);
                $(".validation_for_name_" + id).prop('required', true);
            } else {
                $("#outlet_code_" + id).prop('readonly', true);
                $(".validation_for_code_" + id).prop('required', false);
                $("#outlet_name_" + id).prop('readonly', true);
                $(".validation_for_name_" + id).prop('required', false);
            }
        }


        $('.btn-nxt').on("click", function() {
            $('.editDetails-tabs li.active').next().children('a').trigger("click");
        });
        $('.btn-prev').on("click", function() {
            $('.editDetails-tabs li.active').prev().children('a').trigger("click");
        });

        $scope.get_country_id = function(id) {

            $http.get(
                outlet_get_state_list + '/' + id
            ).then(function(response) {
                self.state = response.data.state;

            });

        }


        $scope.get_state = function(id) {


            $http.get(
                outlet_get_region_list + '/' + id
            ).then(function(response) {

                self.region = response.data.region;
                self.city = response.data.city_data;
            });

        }


        $scope.clearSearchTerm = function() {

            $scope.searchTerm = '';
        };

        $element.find('input').on('keydown', function(ev) {
            ev.stopPropagation();
        });



        var form_id = '#outlets_form';
        var v = jQuery(form_id).validate({
            ignore: '',
            rules: {
                'name': {
                    required: true,
                },
                'code': {
                    required: true,
                },
                'state_id': {
                    required: true,
                },
                'region_id': {
                    required: true,
                },
                'country_id': {
                    required: true,
                },
                'city_id': {
                    required: true,
                },
                'cashier_id': {
                    required: true,
                },
                'pincode': {
                    minlength: 6,
                    maxlength: 6,
                    number: true
                },
                'address_line1': {
                    required: true,
                    minlength: 5,
                    maxlength: 255,
                },

                'businesses[]': {
                    required: true,
                },

            },

            //  messages: {
            //     'name': {
            //         required: 'Outlet Name is Required',

            //     },
            //     'code': {
            //         required: 'Outlet Code is Required',
            //     },

            //     'address_line1': {
            //         required: 'Address line1 is Required',
            //         minlength: 'Enter Minimum 5 Characters!',
            //         maxlength: 'Enter Maximum 255 Characters!',
            //     },
            //     'country_id':{
            //           required: 'Country is Required',
            //     },

            //     'region_id': {
            //         required: 'Region is Required',
            //     },
            //     'state_id': {
            //         required: 'State is Required',
            //     },
            //     'city_id': {
            //         required: 'City is Required',
            //     },
            //     'cashier_id': {
            //         required: 'Central Cashier is Required',
            //     },
            //     'pincode': {
            //         required: 'Pincode is Required',
            //         minlength: 'Enter Minimum 6 Numbers!',
            //         maxlength: 'Enter Maximum 6 Numbers!',
            //         number: 'Enter Numbers Only!'
            //     },

            // },   

            invalidHandler: function(event, validator) {
                new Noty({
                    type: 'error',
                    layout: 'topRight',
                    text: 'You have errors, check all tabs'
                }).show();
            },

            submitHandler: function(form) {

                if ($('#main_business_table').find('.business_sub_check:checked').length == 0) {
                    $(".business_error").addClass('error').text("Business Is Required");
                    $(".business_error").attr('data-set') == 0;
                } else {

                    $(".business_error").removeClass('error').text("");

                    let formData = new FormData($(form_id)[0]);
                    $('#submit').button('loading');
                    $.ajax({
                            url: laravel_routes['outletsave'],
                            method: "POST",
                            data: formData,
                            processData: false,
                            contentType: false,
                        })
                        .done(function(res) {

                            if (res.success == true) {
                                // console.log("in");
                                new Noty({
                                    type: 'success',
                                    layout: 'topRight',
                                    text: res.message,
                                }).show();
                                $location.path('/master/outlets');
                                $scope.$apply();

                            } else {
                                if (!res.success == true) {
                                    $('#submit').button('reset');
                                    var errors = '';
                                    for (var i in res.errors) {
                                        errors += '<li>' + res.errors[i] + '</li>';
                                    }
                                    new Noty({
                                        type: 'error',
                                        layout: 'topRight',
                                        text: errors
                                    }).show();

                                } else {
                                    // console.log("out");
                                    $('#submit').button('reset');

                                    $location.path('/master/outlets');
                                    $scope.$apply();
                                }
                            }
                        })
                        .fail(function(xhr) {
                            $('#submit').button('reset');
                            new Noty({
                                type: 'error',
                                layout: 'topRight',
                                text: 'Something went wrong at server',
                            }).show();
                        });
                }

            },
        });




    }
});