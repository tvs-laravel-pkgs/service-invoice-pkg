<?php

namespace Abs\ServiceInvoicePkg;
use Abs\AxaptaExportPkg\AxaptaExport;
use App\Company;
use App\Customer;
use App\Outlet;
use App\Sbu;
use Auth;
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

	public function outlet() {
		return $this->belongsTo('App\Outlet', 'branch_id', 'id');
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
		// DB::beginTransaction();

		// try {
		$item_codes = [];
		foreach ($this->serviceInvoiceItems as $invoice_item) {
			$item_codes[] = $invoice_item->serviceItem->code;
		}
		if ($this->type_id == 1060) {
			//CN
			$Txt = 'Credit note for';
		} else {
			//DN
			$Txt = 'Debit note for';
		}
		$Txt .= ' ' . implode(',', $item_codes);
		$params = [
			'Voucher' => 'V',
			'AccountType' => 'Customer',
			'LedgerDimension' => $this->customer->code,
			'Txt' => $Txt . '-' . $this->number,
			'AmountCurDebit' => $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') : 0,
			'AmountCurCredit' => $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') : 0,
			'TaxGroup' => '',
			'TVSSACCode' => ($this->serviceInvoiceItems[0]->serviceItem->taxCode != null) ? $this->serviceInvoiceItems[0]->serviceItem->taxCode->code : NULL,
		];
		$this->exportRowToAxapta($params);

		$errors = [];
		foreach ($this->serviceInvoiceItems as $invoice_item) {
			if (!$invoice_item->serviceItem->coaCode) {
				$errors[] = 'COA Code not configured. Item Code : ' . $invoice_item->serviceItem->code;
				continue;
			}
		}
		if (count($errors) > 0) {
			return [
				'success' => false,
				'errors' => $errors,
			];
		}

		foreach ($this->serviceInvoiceItems as $invoice_item) {
			$params = [
				'Voucher' => 'D',
				'AccountType' => 'Ledger',
				'LedgerDimension' => $invoice_item->serviceItem->coaCode->code . '-' . $this->branch->code . '-' . $this->sbu->name,
				'Txt' => $invoice_item->serviceItem->code . ' ' . $invoice_item->serviceItem->description . ' ' . $invoice_item->description . '-' . $this->number,
				'AmountCurDebit' => $this->type_id == 1060 ? $invoice_item->sub_total : 0,
				'AmountCurCredit' => $this->type_id == 1061 ? $invoice_item->sub_total : 0,
				'TaxGroup' => '',
				'TVSSACCode' => ($invoice_item->serviceItem->taxCode != null) ? $invoice_item->serviceItem->taxCode->code : NULL,
			];
			$this->exportRowToAxapta($params);
		}

		return [
			'success' => true,
		];

		// 	DB::commit();
		// 	// dd(1);
		// } catch (\Exception $e) {
		// 	DB::rollback();
		// 	dd($e);
		// }
	}

	protected function exportRowToAxapta($params) {

// $invoice, $sno, $TransDate, $owner, $outlet, $coa_code, $ratio, $bank_detail, $rent_details, $debit, $credit, $voucher, $txt, $payment_modes, $flip, $account_type, $ledger_dimention, $sac_code, $sharing_type_id, $hsn_code = '', $tds_group_in = ''

		$export = new AxaptaExport([
			'company_id' => Auth::user()->company_id,
			'entity_type_id' => 1400,
			'entity_id' => $this->id,
			'LedgerDimension' => $params['LedgerDimension'],
		]);

		$export->CurrencyCode = 'INR';
		$export->JournalName = 'COGLMBBI';
		$export->JournalNum = "";
		$export->Voucher = $params['Voucher'];
		$export->ApproverPersonnelNumber = Auth::user()->employee->code;
		$export->Approved = 1;
		$export->TransDate = date("Y-m-d", strtotime($this->document_date));
		//dd($ledger_dimention);
		$export->AccountType = $params['AccountType'];

		$export->DefaultDimension = $this->sbu->name . '-' . $this->outlet->code;
		$export->Txt = $params['Txt'];
		$export->AmountCurDebit = $params['AmountCurDebit'] > 0 ? $params['AmountCurDebit'] : NULL;
		$export->AmountCurCredit = $params['AmountCurCredit'] > 0 ? $params['AmountCurCredit'] : NULL;
		$export->OffsetAccountType = '';
		$export->OffsetLedgerDimension = '';
		$export->OffsetDefaultDimension = '';
		$export->PaymMode = '';
		$export->TaxGroup = $params['TaxGroup'];
		$export->TaxItemGroup = '';
		$export->Invoice = $this->number;
		$export->SalesTaxFormTypes_IN_FormType = '';
		$export->TDSGroup_IN = $params['TaxGroup'];
		$export->DocumentNum = $this->number;
		$export->DocumentDate = date("Y-m-d", strtotime($this->document_date));
		$export->LogisticsLocation_LocationId = '000127079';
		$export->Due = '';
		$export->PaymReference = '';
		$export->TVSHSNCode = '';
		$export->TVSSACCode = $params['TVSSACCode'];
		$export->TVSVendorLocationID = $this->customer->axapta_location_id;
		$export->TVSCustomerLocationID = '';
		$export->TVSCompanyLocationId = $this->outlet->axapta_location_id ? $this->outlet->axapta_location_id : '';
		$export->save();

	}

}
