<?php

namespace Abs\ServiceInvoicePkg;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceInvoiceItem extends Model {
	use SoftDeletes;
	protected $table = 'service_invoice_items';
	protected $fillable = [
		'created_by_id',
		'updated_by_id',
		'deleted_by_id',
	];

}
