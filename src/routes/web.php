<?php

Route::group(['namespace' => 'Abs\ServiceInvoicePkg', 'middleware' => ['web', 'auth'], 'prefix' => 'cn-dn-pkg'], function () {
	Route::get('/service-invoices/get-list', 'ServiceInvoiceController@getServiceInvoiceList')->name('getServiceInvoiceList');
	Route::get('/service-invoice/save', 'ServiceInvoiceController@saveServiceInvoice')->name('saveServiceInvoice');
});