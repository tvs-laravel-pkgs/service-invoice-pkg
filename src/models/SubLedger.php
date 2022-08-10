<?php

namespace Abs\ServiceInvoicePkg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubLedger extends Model {
	use SoftDeletes;
	protected $table = 'sub_ledger';
	protected $fillable = [
        'company_id',
		'coa_code_id',
		'gl_code',
		'gl_description',
        'created_by_id'
	];
}