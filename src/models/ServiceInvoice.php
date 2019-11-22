<?php

namespace Abs\ServiceInvoicePkg;
use App\Company;
use App\Customer;
use App\Outlet;
use App\Sbu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceInvoice extends Model {
	use SoftDeletes;
	protected $table = 'service_invoices';
	protected $fillable = [
		'branch_id',
		'sbu_id',
		'sub_category_id',
		'invoice_date',
		'document_date',
		'customer_id',
		'items_count',
		'amount_total',
		'tax_total',
		'sub_total',
		'total',
		'created_by_id',
		'updated_by_id',
		'deleted_by_id',
	];

	public function getInvoiceDateAttribute($value) {
		return empty($value) ? '' : date('d-m-Y', strtotime($value));
	}
	public function getDocumentDateAttribute($value) {
		return empty($value) ? '' : date('d-m-Y', strtotime($value));
	}

	public function setInvoiceDateAttribute($date) {
		return $this->attributes['invoice_date'] = empty($date) ? date('Y-m-d') : date('Y-m-d', strtotime($date));
	}
	public function setDocumentDateAttribute($date) {
		return $this->attributes['document_date'] = empty($date) ? date('Y-m-d') : date('Y-m-d', strtotime($date));
	}

	public function serviceItemSubCategory() {
		return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceItemSubCategory', 'sub_category_id', 'id');
	}

	public function customer() {
		return $this->belongsTo('App\Customer', 'customer_id', 'id');
	}

	public function branch() {
		return $this->belongsTo('App\Outlet', 'branch_id', 'id');
	}

	public function sbu() {
		return $this->belongsTo('App\Sbu', 'sbu_id', 'id');
	}

	public function serviceInvoiceItems() {
		return $this->hasMany('Abs\ServiceInvoicePkg\ServiceInvoiceItem', 'service_invoice_id', 'id');
	}

	public function attachments() {
		return $this->hasMany('App\Attachment', 'entity_id', 'id')->where('attachment_of_id', 221)->where('attachment_type_id', 241);
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

	public function outlets() {
		return $this->belongsTo('App\Outlet', 'branch_id', 'id');
	}

	public function sbus() {
		return $this->belongsTo('App\Sbu', 'sbu_id', 'id');
	}

	public function company() {
		return $this->belongsTo('App\Company', 'company_id', 'id');
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

		$outlet = Outlet::where('code', $record_data->outlet_code)->where('company_id', $company->id)->first();
		if (!$outlet) {
			$errors[] = 'Invalid outlet : ' . $record_data->outlet_code;
		}

		$sbu = Sbu::where('name', $record_data->sbu)->where('company_id', $company->id)->first();
		if (!$sbu) {
			$errors[] = 'Invalid sbu : ' . $record_data->sbu;
		}

		$sub_category = ServiceItemSubCategory::where('name', $record_data->sub_category)->where('company_id', $company->id)->first();
		if (!$sub_category) {
			$errors[] = 'Invalid sub_category : ' . $record_data->sub_category;
		}

		$customer = Customer::where('code', $record_data->customer)->where('company_id', $company->id)->first();
		if (!$customer) {
			$errors[] = 'Invalid customer : ' . $record_data->customer;
		}

		if (count($errors) > 0) {
			dump($errors);
			return;
		}

		$record = self::firstOrNew([
			'company_id' => $company->id,
			'number' => $record_data->service_invoice_number,
		]);
		$record->branch_id = $outlet->id;
		$record->sbu_id = $sbu->id;
		$record->sub_category_id = $sub_category->id;
		$record->invoice_date = date('Y-m-d H:i:s', strtotime($record_data->invoice_date));
		$record->document_date = date('Y-m-d H:i:s', strtotime($record_data->document_date));
		$record->customer_id = $customer->id;
		$record->items_count = 0;
		$record->amount_total = 0;
		$record->tax_total = 0;
		$record->sub_total = 0;
		$record->total = 0;
		$record->created_by_id = $admin->id;
		$record->save();
		return $record;
	}

}
