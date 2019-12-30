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

	public function exportToAxapta() {

// $invoice, $sno, $TransDate, $owner, $outlet, $coa_code, $ratio, $bank_detail, $rent_details, $debit, $credit, $voucher, $txt, $payment_modes, $flip, $account_type, $ledger_dimention, $sac_code, $sharing_type_id, $hsn_code = '', $tds_group_in = ''

		if ($sharing_type_id == 9) {
			$ratio_sbu = $ratio->name;
		} else {
			$ratio_sbu = $ratio->sbu->name;
		}
		$row = [];
		$row['entity_type_id'] = $invoice->vendor->vendor_type_id;
		$row['entity_id'] = $invoice->id;
		$row['CurrencyCode'] = config('custom.axapta_common_values.CurrencyCode');
		$row['JournalName'] = config('custom.axapta_common_values.JournalName');
		$row['JournalNum'] = "";
		$row['LineNum'] = $sno + 1;
		$row['Voucher'] = $voucher;
		$row['ApproverPersonnelNumber'] = '30723';
		$row['Approved'] = config('custom.axapta_common_values.Approved');
		$row['TransDate'] = $invoice->approved_date;
		//dd($ledger_dimention);
		$row['AccountType'] = $account_type;
		$row['LedgerDimension'] = $ledger_dimention;

		$row['DefaultDimension'] = $ratio_sbu . '-' . $outlet->code;
		$row['Txt'] = $txt;
		$row['AmountCurDebit'] = $debit > 0 ? $debit : NULL;
		$row['AmountCurCredit'] = $credit > 0 ? $credit : NULL;
		$row['OffsetAccountType'] = '';
		$row['OffsetLedgerDimension'] = '';
		$row['OffsetDefaultDimension'] = '';
		if (isset($payment_modes[$bank_detail->payment_mode])) {
			$row['PaymMode'] = $payment_modes[$bank_detail->payment_mode];
		} else {
			$row['PaymMode'] = '';
		}
		$row['TaxGroup'] = $tds_group_in;
		$row['TaxItemGroup'] = '';
		$row['Invoice'] = $invoice->invoice_number;
		$row['SalesTaxFormTypes_IN_FormType'] = '';
		$row['TDSGroup_IN'] = $tds_group_in;
		$row['DocumentNum'] = $invoice->invoice_number;
		$row['DocumentDate'] = date('d/m/Y', strtotime($invoice->invoice_date));
		$row['LogisticsLocation_LocationId'] = '000127079';
		$row['Due'] = '';
		$row['PaymReference'] = '';
		$row['TVSHSNCode'] = $hsn_code;
		$row['TVSSACCode'] = $sac_code ? $sac_code : '';
		$row['TVSVendorLocationID'] = $invoice->vendor->axapta_location_id;
		$row['TVSCustomerLocationID'] = '';
		$row['TVSCompanyLocationId'] = $outlet->company_location_id;
		$row['customer_code'] = $owner->axapta_code;
		$export = Export::firstOrNew([
			'entity_type_id' => $invoice->vendor->vendor_type_id,
			'entity_id' => $invoice->id,
			'LedgerDimension' => $row['LedgerDimension'],
		]);
		$export->fill($row);
		$export->save();
		//dd($export);
		return $row;

	}

}
