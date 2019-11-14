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

	public $timestamps = false;

	public function serviceInvoice() {
		return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceInvoice', 'service_invoice_id', 'id');
	}

	public function serviceItem() {
		return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceItem', 'service_item_id', 'id');
	}

	public function taxes() {
		return $this->belongsToMany('Abs\TaxPkg\Tax', 'service_invoice_item_tax', 'service_invoice_item_id', 'tax_id')->withPivot(['percentage', 'amount']);
	}
}
