app.component('tcsReportForm', {
    templateUrl: tcs_report_form_template_url,
    controller: function($http, $location, $location, HelperService, $routeParams, $rootScope, $scope) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;

        dateRangePicker();

        self.exportServiceInvoicesToExcelUrl = exportServiceInvoicesToExcelUrl;
        // console.log(self.exportServiceInvoicesToExcelUrl);
        self.csrf_token = $('meta[name="csrf-token"]').attr('content');
        var filter_form_id = '#tcs-export-form';
        var filter_form_v = jQuery(filter_form_id).validate({
            ignore: '',
            rules: {
                'invoice_date': {
                    required: true,
                },
            },
        });

        $scope.reset_filter = function(){
            $('#invoice_date').val('');
            $('#gstin').val('');
        }
    }
});


app.component('gstReportForm', {
    templateUrl: gst_report_form_template_url,
    controller: function($http, $location, $location, HelperService, $routeParams, $rootScope, $scope) {
        var self = this;
        self.hasPermission = HelperService.hasPermission;
        self.angular_routes = angular_routes;

        dateRangePicker();

        self.exportServiceInvoicesToExcelUrl = exportServiceInvoicesToExcelUrl;
        self.csrf_token = $('meta[name="csrf-token"]').attr('content');
        var filter_form_id = '#gst-export-form';
        var filter_form_v = jQuery(filter_form_id).validate({
            ignore: '',
            rules: {
                'invoice_date': {
                    required: true,
                },
                'gstin': {
                    required: true,
                },
            },
        });

        $scope.reset_filter = function(){
            $('#invoice_date').val('');
            $('#gstin').val('');
        }
    }
});