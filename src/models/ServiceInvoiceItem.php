<?php

namespace Abs\ServiceInvoicePkg;
use App\Company;
use Illuminate\Database\Eloquent\Model;

class ServiceInvoiceItem extends Model {
	protected $table = 'service_invoice_items';
	protected $fillable = [
		'service_invoice_id',
		'service_item_id',
		'e_invoice_uom_id',
		'description',
		'qty',
		'rate',
		'sub_total',
		'is_discount',
	];

	public $timestamps = false;

	public function serviceInvoice() {
		return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceInvoice', 'service_invoice_id', 'id');
	}

	public function eavVarchars() {
		return $this->belongsToMany('App\Config', 'eav_varchar', 'entity_id', 'entity_type_id')->withPivot(['field_group_id', 'field_id', 'value']);
	}

	public function eavInts() {
		return $this->belongsToMany('App\Config', 'eav_int', 'entity_id', 'entity_type_id')->withPivot(['field_group_id', 'field_id', 'value']);
	}

	public function eavDatetimes() {
		return $this->belongsToMany('App\Config', 'eav_datetime', 'entity_id', 'entity_type_id')->withPivot(['field_group_id', 'field_id', 'value']);
	}

	public function serviceItem() {
		return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceItem', 'service_item_id', 'id');
	}

	public function eInvoiceUom() {
		return $this->belongsTo('App\EInvoiceUom', 'e_invoice_uom_id', 'id');
	}

	public function taxes() {
		return $this->belongsToMany('Abs\TaxPkg\Tax', 'service_invoice_item_tax', 'service_invoice_item_id', 'tax_id')->withPivot(['percentage', 'amount']);
	}

	public static function createFromCollection($records, $company = null) {
		foreach ($records as $key => $record_data) {
			try {
				if (!$record_data->company) {
					continue;
				}
				$record = self::createFromObject($record_data, $company);
			} catch (Exception $e) {
				dd($e);
			}
		}
	}

	public static function createFromObject($record_data, $company = null) {
		if (!$company) {
			$company = Company::where('code', $record_data->company)->first();
		}
		$admin = $company->admin();

		$errors = [];
		if (!$company) {
			$errors[] = 'Invalid Company : ' . $record_data->company;
		}

		$service_invoice = ServiceInvoice::where('number', $record_data->service_invoice_number)->where('company_id', $company->id)->first();
		if (!$service_invoice) {
			$errors[] = 'Invalid service invoice number : ' . $record_data->service_invoice_number;
		}

		$service_item = ServiceItem::where('code', $record_data->service_item_code)->where('company_id', $company->id)->first();
		if (!$service_item) {
			$errors[] = 'Invalid service item code : ' . $record_data->service_item_code;
		}

		if (count($errors) > 0) {
			dump($errors);
			return;
		}

		$record = self::firstOrNew([
			'service_invoice_id' => $service_invoice->id,
			'service_item_id' => $service_item->id,
		]);
		$record->description = $record_data->description;
		$record->qty = $record_data->qty;
		$record->rate = $record_data->rate;
		$record->sub_total = $record->qty * $record->rate;
		$record->save();

		if ($service_invoice->branch->state->id == $service_invoice->customer->primaryAddress->state->id) {
			$tax_type_id = 1160; //With State
		} else {
			$tax_type_id = 1161; //Inter State
		}
		$taxes = $service_item->taxCode->taxes;
		foreach ($taxes as $tax) {
			if ($tax->type_id == $tax_type_id) {
				// dump($tax->pivot->percentage);
				$record->taxes()->syncWithoutDetaching([
					$tax->id => [
						'percentage' => $tax->pivot->percentage,
						'amount' => round($record->sub_total * $tax->pivot->percentage / 100, 2),
					],
				]);
			}
		}

		$service_invoice->items_count = $service_invoice->serviceInvoiceItems()->count('id');
		$service_invoice->amount_total = $service_invoice->serviceInvoiceItems()->sum('sub_total');
		$service_invoice->save();

		return $record;
	}
}
