<?php

Route::group(['namespace' => 'Abs\ServiceInvoicePkg', 'middleware' => ['web', 'auth'], 'prefix' => 'service-invoice-pkg'], function () {
	Route::get('/service-invoices/get-list', 'ServiceInvoiceController@getServiceInvoiceList')->name('getServiceInvoiceList');
	Route::get('/service-invoice/get-form-data/{type_id}/{id?}', 'ServiceInvoiceController@getFormData')->name('getServiceInvoiceFormdata');
	Route::post('/service-invoice/save', 'ServiceInvoiceController@saveServiceInvoice')->name('saveServiceInvoice');
	Route::get('/service-invoice/create-pdf/{id?}', 'ServiceInvoiceController@createPdf')->name('createPdf');
	Route::get('/service-invoice/view/{type_id}/{id?}', 'ServiceInvoiceController@viewServiceInvoice')->name('viewServiceInvoice');
	Route::post('/service-invoice/send-to-approval', 'ServiceInvoiceController@saveApprovalStatus')->name('saveApprovalStatus');
	Route::post('/service-invoice/send-multiple-approval', 'ServiceInvoiceController@sendMultipleApproval')->name('sendMultipleApproval');
	Route::get('/service-invoice/filter', 'ServiceInvoiceController@getServiceInvoiceFilter')->name('getServiceInvoiceFilter');
	Route::post('/service-invoice/export', 'ServiceInvoiceController@exportServiceInvoicesToExcel')->name('exportServiceInvoicesToExcel');

	//SERVICE-INVOICE-APPROVAL
	Route::get('/service-invoices/cn-dn-approvals/get-list', 'ServiceInvoiceApprovalController@getServiceInvoiceApprovalList')->name('getServiceInvoiceApprovalList');
	Route::get('/service-invoice/cn-dn-approvals/approval/view/{approval_type_id?}/{type_id?}/{id?}', 'ServiceInvoiceApprovalController@viewServiceInvoiceApproval')->name('viewServiceInvoiceApproval');
	Route::get('/service-invoice/cn-dn-approvals/', 'ServiceInvoiceApprovalController@approvalTypeValid')->name('approvalTypeValid');
	Route::post('/service-invoice/cn-dn-approvals/save', 'ServiceInvoiceApprovalController@updateApprovalStatus')->name('updateApprovalStatus');
	Route::post('/service-invoice/update-multiple-approvals', 'ServiceInvoiceApprovalController@updateMultipleApproval')->name('updateMultipleApproval');
	Route::get('/service-invoice/cn-dn-approvals/filter', 'ServiceInvoiceApprovalController@getApprovalFilter')->name('getApprovalFilter');
	//GET SBUs
	Route::get('/get-sbu/{outlet_id?}', 'ServiceInvoiceController@getSbus')->name('getSbus');

	//GET SERVICE ITEM SUB CATEGORIES
	Route::get('/get-service-item-sub-category/{service_item_category_id?}', 'ServiceInvoiceController@getServiceItemSubCategories')->name('getServiceItemSubCategories');

	// FIELD
	Route::post('/field/search', 'ServiceInvoiceController@searchField')->name('searchField');
	Route::post('/get-field-details', 'ServiceInvoiceController@getFieldDetails')->name('getFieldDetails');

	// BRANCH
	Route::post('/branch/search', 'ServiceInvoiceController@searchBranch')->name('searchBranch');
	Route::post('/get-branch-details', 'ServiceInvoiceController@getBranchDetails')->name('getBranchDetails');

	// CUSTOMER
	Route::post('/service-invoice/customer/search', 'ServiceInvoiceController@searchCustomer')->name('searchCustomer');
	Route::post('/service-invoice/get-customer-details', 'ServiceInvoiceController@getCustomerDetails')->name('getCustomerDetails');

	// SERVICE ITEM
	Route::post('/service-invoice/service-item/search', 'ServiceInvoiceController@searchServiceItem')->name('searchServiceItem');
	Route::post('/service-invoice/get-service-item-details', 'ServiceInvoiceController@getServiceItemDetails')->name('getServiceItemDetails');
	Route::post('/service-invoice/service-item/get', 'ServiceInvoiceController@getServiceItem')->name('getServiceItem');

	//SERVICE ITEM CATEGORIES
	Route::get('/service-item-categories/get-list', 'ServiceItemCategoryController@getServiceItemCategoryList')->name('getServiceItemCategoryList');
	Route::get('/service-item-category/get-form-data/{id?}', 'ServiceItemCategoryController@getServiceItemCategoryFormData')->name('getServiceItemCategoryFormData');
	Route::post('/service-item-category/save', 'ServiceItemCategoryController@saveServiceItemCategory')->name('saveServiceItemCategory');
	Route::get('/service-item-category/delete/{id?}', 'ServiceItemCategoryController@serviceItemCategoryDelete')->name('serviceItemCategoryDelete');

	//SERVICE ITEMS
	Route::get('/service-items/get-list', 'ServiceItemController@getServiceItemList')->name('getServiceItemList');
	Route::get('/service-item/get-form-data/{id?}', 'ServiceItemController@getServiceItemFormData')->name('getServiceItemFormData');
	Route::post('/service-item/save', 'ServiceItemController@saveServiceItem')->name('saveServiceItem');
	Route::get('/service-item/get-sub-category/{id}', 'ServiceItemController@getSubCategory')->name('getSubCategory');
	Route::get('/service-item/delete/{id?}', 'ServiceItemController@serviceItemDelete')->name('serviceItemDelete');
	Route::get('/service-items/filter', 'ServiceItemController@getServiceItemFilter')->name('getServiceItemFilter');
	Route::post('/service-items/search-coa-code', 'ServiceItemController@searchCoaCode')->name('searchCoaCode');
	Route::post('/service-items/search-sac-code', 'ServiceItemController@searchSacCode')->name('searchSacCode');
});