<?php
Route::group(['namespace' => 'Abs\CnDnPkg\Api', 'middleware' => ['api']], function () {
	Route::group(['prefix' => 'cn-dn-pkg/api'], function () {
		Route::group(['middleware' => ['auth:api']], function () {
		});
	});
});