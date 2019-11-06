app.config(['$routeProvider', function($routeProvider) {

    $routeProvider.
    //DN
    when('/cn-dn-pkg/dn/list', {
        template: '<dn-list></dn-list>',
        title: 'Debit Notes',
    }).
    when('/cn-dn-pkg/dn/add', {
        template: '<dn-form></dn-form>',
        title: 'Add Debit Note',
    }).
    when('/cn-dn-pkg/dn/edit/:id', {
        template: '<dn-form></dn-form>',
        title: 'Edit Debit Note',
    })

}]);