<?php

Route::group(['namespace' => 'Abs\CnDnPkg', 'middleware' => ['web', 'auth'], 'prefix' => 'cn-dn-pkg'], function () {
	Route::get('/dns/get-list', 'DnController@getDnList')->name('getDnList');
	Route::get('/dn/save', 'DnController@saveDn')->name('saveDn');
});