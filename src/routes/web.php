<?php

Route::group(['namespace' => 'Abs\ServiceInvoicePkg', 'middleware' => ['web', 'auth'], 'prefix' => 'service-invoice-pkg'], function () {
	Route::get('/service-invoices/get-list', 'ServiceInvoiceController@getServiceInvoiceList')->name('getServiceInvoiceList');
	Route::get('/service-invoice/get-form-data/{id?}', 'ServiceInvoiceController@getFormData')->name('getServiceInvoiceFormdata');
	Route::get('/service-invoice/save', 'ServiceInvoiceController@saveServiceInvoice')->name('saveServiceInvoice');

	Route::get('/get-service-item-sub-category/{service_item_category_id?}', 'ServiceInvoiceController@getServiceItemSubCategories')->name('getServiceItemSubCategories');

	// CUSTOMER
	Route::post('/service-invoice/customer/search', 'ServiceInvoiceController@searchCustomer')->name('searchCustomer');
	Route::post('/service-invoice/get-customer-details', 'ServiceInvoiceController@getCustomerDetails')->name('getCustomerDetails');

	// SERVICE ITEM
	Route::post('/service-invoice/service-item/search', 'ServiceInvoiceController@searchServiceItem')->name('searchServiceItem');
	Route::post('/service-invoice/get-service-item-details', 'ServiceInvoiceController@getServiceItemDetails')->name('getServiceItemDetails');

});