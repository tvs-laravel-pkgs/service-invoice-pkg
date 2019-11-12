<?php

Route::group(['namespace' => 'Abs\ServiceInvoicePkg', 'middleware' => ['web', 'auth'], 'prefix' => 'service-invoice-pkg'], function () {
	Route::get('/service-invoices/get-list', 'ServiceInvoiceController@getServiceInvoiceList')->name('getServiceInvoiceList');
	Route::get('/service-invoice/get-form-data/{id?}', 'ServiceInvoiceController@getServiceInvoiceFormdata')->name('getServiceInvoiceFormdata');
	Route::get('/service-invoice/save', 'ServiceInvoiceController@saveServiceInvoice')->name('saveServiceInvoice');

	//SERVICE ITEM CATEGORIES
	Route::get('/service-item-categories/get-list', 'ServiceItemCategoryController@getServiceItemCategoryList')->name('getServiceItemCategoryList');
	Route::get('/service-item-category/get-form-data/{id?}', 'ServiceItemCategoryController@getServiceItemCategoryFormData')->name('getServiceItemCategoryFormData');
	Route::post('/service-item-category/save', 'ServiceItemCategoryController@saveServiceItemCategory')->name('saveServiceItemCategory');

	//SERVICE ITEMS
	Route::get('/service-items/get-list', 'ServiceItemController@getServiceItemList')->name('getServiceItemList');
	Route::get('/service-item/get-form-data/{id?}', 'ServiceItemCategoryController@getServiceItemFormData')->name('getServiceItemFormData');
	Route::post('/service-item/save', 'ServiceItemController@saveServiceItem')->name('saveServiceItem');
});