app.config(['$routeProvider', function($routeProvider) {

    $routeProvider.
    //DN
    when('/service-invoice-pkg/service-invoice/list', {
        template: '<service-invoice-list></service-invoice-list>',
        title: 'Service Invoices',
    }).
    when('/service-invoice-pkg/service-invoice/add', {
        template: '<service-invoice-form></service-invoice-form>',
        title: 'Add Service Invoice',
    }).
    when('/service-invoice-pkg/service-invoice/edit/:id', {
        template: '<service-invoice-form></service-invoice-form>',
        title: 'Edit Service Invoice',
    })

}]);