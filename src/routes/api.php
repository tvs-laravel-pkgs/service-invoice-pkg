<?php
Route::group(['namespace' => 'Abs\ServiceInvoicePkg\Api', 'middleware' => ['api']], function () {
	Route::group(['prefix' => 'service-invoice-pkg/api'], function () {
		Route::group(['middleware' => ['auth:api']], function () {
		});
	});
});