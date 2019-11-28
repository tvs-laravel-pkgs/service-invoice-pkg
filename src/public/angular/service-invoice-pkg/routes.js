app.config(['$routeProvider', function($routeProvider) {

    $routeProvider.
    //SERVICE INVOICE
    when('/service-invoice-pkg/service-invoice/list', {
        template: '<service-invoice-list></service-invoice-list>',
        title: 'CN/DNs',
    }).
    when('/service-invoice-pkg/service-invoice/add', {
        template: '<service-invoice-form></service-invoice-form>',
        title: 'Add CN/DN',
    }).
    when('/service-invoice-pkg/service-invoice/edit/:id', {
        template: '<service-invoice-form></service-invoice-form>',
        title: 'Edit CN/DN',
    }).
    when('/service-invoice-pkg/service-invoice/view/:id', {
        template: '<service-invoice-view></service-invoice-view>',
        title: 'View CN/DN',
    }).

    //SERVICE INVOICE APPROVALS
    when('/service-invoice-pkg/service-invoice/approval/approval-level/:approval_level_id/list/', {
        template: '<service-invoice-approval-list></service-invoice-approval-list>',
        title: 'CN/DN Approval List',
    }).
    when('/service-invoice-pkg/service-invoice/approval/approval-level/:approval_level_id/view/:service_invoice_id', {
        template: '<service-invoice-approval-view></service-invoice-approval-view>',
        title: 'CN/DN Approval View',
    }).

    //SERVICE ITEM CATEGORIES
    when('/service-invoice-pkg/service-item-category/list', {
        template: '<service-item-category-list></service-item-category-list>',
        title: 'Service Item Categories',
    }).
    when('/service-invoice-pkg/service-item-category/add', {
        template: '<service-item-category-form></service-item-category-form>',
        title: 'Add Service Item Category',
    }).
    when('/service-invoice-pkg/service-item-category/edit/:id', {
        template: '<service-item-category-form></service-item-category-form>',
        title: 'Edit Service Item Category',
    }).

    //SERVICE ITEMS
    when('/service-invoice-pkg/service-item/list', {
        template: '<service-item-list></service-item-list>',
        title: 'Service Items',
    }).
    when('/service-invoice-pkg/service-item/add', {
        template: '<service-item-form></service-item-form>',
        title: 'Add Service Item',
    }).
    when('/service-invoice-pkg/service-item/edit/:id', {
        template: '<service-item-form></service-item-form>',
        title: 'Edit Service Item',
    });


}]);