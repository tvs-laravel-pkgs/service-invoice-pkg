app.config(['$routeProvider', function($routeProvider) {

    $routeProvider.
    //DN
    when('/service-invoice-pkg/dn/list', {
        template: '<service-invoice-list></dn-list>',
        title: 'Service Invoices',
    }).
    when('/service-invoice-pkg/dn/add', {
        template: '<service-invoice-form></service-invoice-form>',
        title: 'Add Service Invoice',
    }).
    when('/service-invoice-pkg/service-invoice/edit/:id', {
        template: '<service-invoice-form></service-invoice-form>',
        title: 'Edit Debit Note',
    })

}]);