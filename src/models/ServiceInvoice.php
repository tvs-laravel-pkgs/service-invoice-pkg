<?php

namespace Abs\ServiceInvoicePkg;
use Abs\AxaptaExportPkg\AxaptaExport;
use Abs\ImportCronJobPkg\ImportCronJob;
use Abs\SerialNumberPkg\SerialNumberGroup;
use Abs\TaxPkg\Tax;
use Abs\TaxPkg\TaxCode;
use App\Company;
use App\Config;
use App\Customer;
use App\Entity;
use App\FinancialYear;
use App\Outlet;
use App\Sbu;
use Auth;
use DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPExcel_Shared_Date;

class ServiceInvoice extends Model {
	use SoftDeletes;
	protected $table = 'service_invoices';
	protected $fillable = [
		'company_id',
		'number',
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

	public function exportToAxapta($delete = false) {
		// DB::beginTransaction();

		if ($delete) {
			AxaptaExport::where([
				'company_id' => Auth::user()->company_id,
				'entity_type_id' => 1400,
				'entity_id' => $this->id,
			])->delete();
		}
		// try {
		$item_codes = [];
		foreach ($this->serviceInvoiceItems as $invoice_item) {
			$item_codes[] = $invoice_item->serviceItem->code;
			$item_descriptions[] = $invoice_item->description;
		}
		$Txt = implode(',', $item_descriptions);
		if ($this->type_id == 1060) {
			//CN
			$Txt .= ' - Credit note for ';
		} else {
			//DN
			$Txt .= ' - Debit note for ';
		}
		$Txt .= implode(',', $item_codes);
		$params = [
			'Voucher' => 'V',
			'AccountType' => 'Customer',
			'LedgerDimension' => $this->customer->code,
			'Txt' => $Txt . '-' . $this->number,
			'AmountCurDebit' => $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') : 0,
			'AmountCurCredit' => $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') : 0,
			'TaxGroup' => '',
		];

		if ($this->serviceInvoiceItems[0]->taxCode) {
			if ($this->serviceInvoiceItems[0]->taxCode->type_id == 1020) {
				//HSN Code
				$params['TVSHSNCode'] = $this->serviceInvoiceItems[0]->taxCode->code;
				$params['TVSSACCode'] = '';
			} else {
				$params['TVSHSNCode'] = '';
				$params['TVSSACCode'] = $this->serviceInvoiceItems[0]->taxCode->code;
			}
		} else {
			$params['TVSHSNCode'] = $params['TVSSACCode'] = NULL;
		}

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
				'Txt' => $invoice_item->serviceItem->code . ' ' . $invoice_item->serviceItem->description . ' ' . $invoice_item->description . '-' . $this->number . '-' . $this->customer->code,
				'AmountCurDebit' => $this->type_id == 1060 ? $invoice_item->sub_total : 0,
				'AmountCurCredit' => $this->type_id == 1061 ? $invoice_item->sub_total : 0,
				'TaxGroup' => '',
				// 'TVSSACCode' => ($invoice_item->serviceItem->taxCode != null) ? $invoice_item->serviceItem->taxCode->code : NULL,
			];

			if ($invoice_item->serviceItem->taxCode) {
				if ($invoice_item->serviceItem->taxCode->type_id == 1020) {
					//HSN Code
					$params['TVSHSNCode'] = $invoice_item->serviceItem->taxCode->code;
					$params['TVSSACCode'] = '';
				} else {
					$params['TVSHSNCode'] = '';
					$params['TVSSACCode'] = $invoice_item->serviceItem->taxCode->code;
				}
			} else {
				$params['TVSHSNCode'] = $params['TVSSACCode'] = NULL;
			}
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

		$params['TVSHSNCode'] = isset($params['TVSHSNCode']) ? $params['TVSHSNCode'] : '';
		$export->CurrencyCode = 'INR';
		$export->JournalName = 'BPAS_NJV';
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
		$export->TVSHSNCode = $params['TVSHSNCode'];
		$export->TVSSACCode = $params['TVSSACCode'];
		$export->TVSVendorLocationID = $params['TVSHSNCode'] || $params['TVSSACCode'] ? $this->customer->axapta_location_id : '';
		$export->TVSCustomerLocationID = '';
		$export->TVSCompanyLocationId = ($params['TVSHSNCode'] || $params['TVSSACCode']) && $this->outlet->axapta_location_id ? $this->outlet->axapta_location_id : '';
		$export->save();

	}

	public static function importFromExcel($job) {
		try {
			$response = ImportCronJob::getRecordsFromExcel($job, 'N');
			$rows = $response['rows'];
			$header = $response['header'];

			$all_error_records = [];
			foreach ($rows as $k => $row) {
				$record = [];
				foreach ($header as $key => $column) {
					if (!$column) {
						continue;
					} else {
						$record[$column] = trim($row[$key]);
					}
				}
				$original_record = $record;
				$status = [];
				$status['errors'] = [];
				// if (empty($record['Reference Number'])) {
				// 	$status['errors'][] = 'Type is empty';
				// }

				if (empty($record['Type'])) {
					$status['errors'][] = 'Type is empty';
				} else {
					$type = Config::where([
						'config_type_id' => 84,
						'name' => $record['Type'],
					])->first();
					if (!$type) {
						$status['errors'][] = 'Invalid Type';
					}
				}

				if (empty($record['Doc Date'])) {
					$status['errors'][] = 'Doc Date is empty';
				} else {
					if (!is_numeric($record['Doc Date'])) {
						$status['errors'][] = 'Invalid Date Format';
					}
				}

				if (empty($record['Branch'])) {
					$status['errors'][] = 'Branch is empty';
				} else {
					$branch = Outlet::where([
						'company_id' => $job->company_id,
						'code' => $record['Branch'],
					])->first();
					if (!$branch) {
						$status['errors'][] = 'Invalid Branch';
					}
				}

				if (empty($record['SBU'])) {
					$status['errors'][] = 'SBU is empty';
				} else {
					$sbu = Sbu::where([
						'company_id' => $job->company_id,
						'name' => $record['SBU'],
					])->first();
					if (!$sbu) {
						$status['errors'][] = 'Invalid SBU';
					}
					// $outlet_sbu = $branch->outlet_sbu;
					// if (!$outlet_sbu) {
					// 	$status['errors'][] = 'SBU is not mapped for this branch';
					// }
				}

				if (empty($record['Category'])) {
					$status['errors'][] = 'Category is empty';
				} else {
					$category = ServiceItemCategory::where([
						'company_id' => $job->company_id,
						'name' => $record['Category'],
					])->first();
					if (!$category) {
						$status['errors'][] = 'Invalid Category';
					} else {
						if (empty($record['Sub Category'])) {
							$status['errors'][] = 'Sub Category is empty';
						} else {
							$sub_category = ServiceItemSubCategory::where([
								'company_id' => $job->company_id,
								'category_id' => $category->id,
								'name' => $record['Sub Category'],
							])->first();
							if (!$sub_category) {
								$status['errors'][] = 'Invalid Sub Category Or Sub Category is not mapped for this Category';
							}
						}
					}
				}

				if (empty($record['Customer Code'])) {
					$status['errors'][] = 'Customer Code is empty';
				} else {
					$customer = Customer::where([
						'company_id' => $job->company_id,
						'code' => $record['Customer Code'],
					])->first();
					if (!$customer) {
						$status['errors'][] = 'Invalid Customer';
					}
				}

				if (empty($record['Item Code'])) {
					$status['errors'][] = 'Item Code is empty';
				} else {
					$item_code = ServiceItem::where([
						'company_id' => $job->company_id,
						'code' => $record['Item Code'],
					])->first();
					if (!$item_code) {
						$status['errors'][] = 'Invalid Item Code';
					}
				}

				if (empty($record['Reference'])) {
					$status['errors'][] = 'Reference is empty';
				}

				if (empty($record['Amount'])) {
					$status['errors'][] = 'Amount is empty';
				} elseif (!is_numeric($record['Amount'])) {
					$status['errors'][] = 'Invalid Amount';
				}

				//GET FINANCIAL YEAR ID BY DOCUMENT DATE
				try {
					$document_date_year = date('Y', PHPExcel_Shared_Date::ExcelToPHP($record['Doc Date']));
					$financial_year = FinancialYear::where('from', $document_date_year)
						->where('company_id', $job->company_id)
						->first();
					if (!$financial_year) {
						$status['errors'][] = 'Fiancial Year Not Found';
					}
				} catch (\Exception $e) {
					$status['errors'][] = 'Invalid Date Format';

				}

				if ($type) {
					if ($type->id == 1061) {
						//DN
						$serial_number_category = 5;
					} elseif ($type->id == 1060) {
						//CN
						$serial_number_category = 4;
					}

					if ($branch && $sbu && $financial_year) {
						//GENERATE SERVICE INVOICE NUMBER
						$generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, $branch->id, $sbu);
						if (!$generateNumber['success']) {
							$status['errors'][] = 'No Serial number found';
						}
					}

				}

				$approval_status = Entity::select('entities.name')->where('company_id', $job->company_id)->where('entity_type_id', 18)->first();
				if ($approval_status) {
					$status_id = $approval_status->name;
				} else {
					$status['errors'][] = 'Initial CN/DN Status has not mapped.!';
				}

				if ($item_code && $branch && $customer) {
					$taxes = Tax::getTaxes($item_code->id, $branch->id, $customer->id);
					if (!$taxes['success']) {
						$status['errors'][] = $taxes['error'];
					}
				}

				if (count($status['errors']) > 0) {
					// dump($status['errors']);
					$original_record['Record No'] = $k + 1;
					$original_record['Error Details'] = implode(',', $status['errors']);
					$all_error_records[] = $original_record;
					$job->incrementError();
					continue;
				}

				DB::beginTransaction();

				// dd(Auth::user()->company_id);
				$service_invoice = ServiceInvoice::firstOrNew([
					'company_id' => $job->company_id,
					'number' => $generateNumber['number'],
				]);
				if ($type->id == 1061) {
					$service_invoice->is_cn_created = 0;
				} elseif ($type->id == 1060) {
					$service_invoice->is_cn_created = 1;
				}

				$service_invoice->company_id = $job->company_id;
				$service_invoice->type_id = $type->id;
				$service_invoice->branch_id = $branch->id;
				$service_invoice->sbu_id = $sbu->id;
				$service_invoice->sub_category_id = $sub_category->id;
				$service_invoice->invoice_date = date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($record['Doc Date']));
				$service_invoice->document_date = date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($record['Doc Date']));
				$service_invoice->customer_id = $customer->id;
				$message = 'Service invoice added successfully';
				$service_invoice->items_count = 1;
				$service_invoice->status_id = $status_id;
				$service_invoice->created_by_id = $job->created_by_id;
				$service_invoice->updated_at = NULL;
				$service_invoice->save();

				$service_invoice_item = ServiceInvoiceItem::firstOrNew([
					'service_invoice_id' => $service_invoice->id,
					'service_item_id' => $item_code->id,
				]);
				$service_invoice_item->description = $record['Reference'];
				$service_invoice_item->qty = 1;
				$service_invoice_item->rate = $record['Amount'];
				$service_invoice_item->sub_total = 1 * $record['Amount'];
				$service_invoice_item->save();

				//SAVE SERVICE INVOICE ITEM TAX
				$total_tax_amount = 0;

				if ($item_code->sac_code_id) {
					$tax_code = TaxCode::find($item_code->sac_code_id)->first();
					$tax_percentages = DB::table('tax_code_tax')
						->join('taxes', 'taxes.id', 'tax_code_tax.tax_id')
						->where('tax_code_id', $tax_code->id)
						->whereIn('tax_id', $taxes['tax_ids'])
						->get()
						->toArray()
					;
					// dd($tax_percentages);
					$service_invoice_item->taxes()->sync([]);
					foreach ($tax_percentages as $tax) {
						$service_invoice_item->taxes()->attach($tax->tax_id, ['percentage' => $tax->percentage, 'amount' => self::percentage(1 * $record['Amount'], $tax->percentage)]);
						// $tax_amount[$tax->name] = self::percentage(1 * $record['Amount'], $tax->percentage);
						$total_tax_amount += self::percentage(1 * $record['Amount'], $tax->percentage);
					}
				}
				$service_invoice->amount_total = $record['Amount'];
				$service_invoice->tax_total = $item_code->sac_code_id ? $total_tax_amount : 0;
				$service_invoice->sub_total = 1 * $record['Amount'];
				$service_invoice->total = $record['Amount'] + $total_tax_amount;
				$service_invoice->save();

				$job->incrementNew();

				DB::commit();
				//UPDATING PROGRESS FOR EVERY FIVE RECORDS
				if (($k + 1) % 5 == 0) {
					$job->save();
				}
			}

			//COMPLETED or completed with errors
			$job->status_id = $job->error_count == 0 ? 7202 : 7205;
			$job->save();

			ImportCronJob::generateImportReport([
				'job' => $job,
				'all_error_records' => $all_error_records,
			]);

		} catch (\Throwable $e) {
			$job->status_id = 7203; //Error
			$job->error_details = 'Error:' . $e->getMessage() . '. Line:' . $e->getLine() . '. File:' . $e->getFile(); //Error
			$job->save();
			dump($job->error_details);
		}

	}

	public static function percentage($num, $per) {
		return ($num / 100) * $per;
	}
}
