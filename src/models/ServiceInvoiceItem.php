<?php

namespace Abs\ServiceInvoicePkg;

use Illuminate\Database\Eloquent\Model;

class ServiceInvoiceItem extends Model {
	protected $table = 'service_invoice_items';
	protected $fillable = [
		'service_invoice_id',
		'service_item_id',
		'description',
		'qty',
		'rate',
		'sub_total',
	];

	public function serviceInvoice() {
		return $this->belongsTo('Abs\AttributePkg\ServiceInvoice', 'service_invoice_id', 'id');
	}
}
