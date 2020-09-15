<?php

namespace Abs\ServiceInvoicePkg;
use Abs\AttributePkg\Field;
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
use DB;
use File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PDF;
use PHPExcel_Shared_Date;

class ServiceInvoice extends Model {
	use SoftDeletes;
	protected $table = 'service_invoices';
	protected $fillable = [
		'company_id',
		'number',
		'branch_id',
		'sbu_id',
		'category_id',
		'sub_category_id',
		'invoice_date',
		'document_date',
		'to_account_type_id',
		'customer_id',
		'items_count',
		'amount_total',
		'tax_total',
		'sub_total',
		'total',
		'is_service',
		'is_reverse_charge_applicable',
		'po_reference_number',
		'invoice_number',
		'round_off_amount',
		'final_amount',
		'irn_number',
		'qr_image',
		'ack_no',
		'ack_date',
		'version',
		'irn_request',
		'irn_response',
		'created_by_id',
		'updated_by_id',
		'deleted_by_id',
	];

	public function getInvoiceDateAttribute($value) {
		return empty($value) ? '' : date('d-m-Y', strtotime($value));
	}

	public function createdBy() {
		return $this->belongsTo('App\User', 'created_by_id');
	}

	public function getDocumentDateAttribute($value) {
		return empty($value) ? '' : date('d-m-Y', strtotime($value));
	}

	public function setInvoiceDateAttribute($date) {
		return $this->attributes['invoice_date'] = empty($date) ? NULL : date('Y-m-d', strtotime($date));
	}
	public function setDocumentDateAttribute($date) {
		return $this->attributes['document_date'] = empty($date) ? date('Y-m-d') : date('Y-m-d', strtotime($date));
	}

	public function getAckDateAttribute($date) {
		return $this->attributes['ack_date'] = empty($date) ? NULL : date('Y-m-d', strtotime($date));
	}

	public function serviceItemSubCategory() {
		return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceItemSubCategory', 'sub_category_id', 'id');
	}

	public function serviceItemCategory() {
		return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceItemCategory', 'category_id', 'id');
	}

	public function toAccount() {
		if ($this->to_account_type_id == 1440) {
			//customer
			return $this->belongsTo('Abs\CustomerPkg\Customer', 'customer_id');
		} elseif ($this->to_account_type_id == 1441) {
			//vendor
			return $this->belongsTo('App\Vendor', 'customer_id');
		} elseif ($this->to_account_type_id == 1442) {
			//ledger
			return $this->belongsTo('Abs\JVPkg\Ledger', 'customer_id');
		}
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
				'company_id' => $this->company_id,
				'entity_type_id' => 1400,
				'entity_id' => $this->id,
			])->delete();
		}
		// try {
		$item_codes = [];
		$total_amount_with_gst['debit'] = 0;
		$total_amount_with_gst['credit'] = 0;
		$KFC_IN = 0;
		foreach ($this->serviceInvoiceItems as $invoice_item) {
			$service_invoice = $invoice_item->serviceInvoice()->with([
				'customer',
				'customer.primaryAddress',
				'branch',
				'branch.primaryAddress',
			])
				->first();
			if (!empty($service_invoice)) {
				if ($service_invoice->customer->primaryAddress->state_id) {
					if ($service_invoice->customer->primaryAddress->state_id == 3 && $service_invoice->branch->primaryAddress->state_id == 3) {
						if (empty($service_invoice->customer->gst_number)) {
							if (!empty($invoice_item->serviceItem->taxCode)) {
								$KFC_IN = 1;
								foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
									if ($tax->name == 'CGST') {
										$total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

										$total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

									}
									//FOR CGST
									if ($tax->name == 'SGST') {
										$total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

										$total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
									}
								}
								//FOR KFC
								if ($invoice_item->serviceItem->taxCode) {
									$total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;

									$total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
								}
							}
						}
					}
				}
			}
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

		if ($total_amount_with_gst['debit'] == 0 && $total_amount_with_gst['credit'] == 0) {
			$params = [
				'Voucher' => 'V',
				'AccountType' => 'Customer',
				'LedgerDimension' => $this->customer->code,
				'Txt' => $Txt . '-' . $this->number,
				'AmountCurDebit' => $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') : 0,
				'AmountCurCredit' => $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') : 0,
				'TaxGroup' => '',
			];
		} else {
			$params = [
				'Voucher' => 'V',
				'AccountType' => 'Customer',
				'LedgerDimension' => $this->customer->code,
				'Txt' => $Txt . '-' . $this->number,
				'AmountCurDebit' => ($total_amount_with_gst['debit'] + ($this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') : 0)),
				'AmountCurCredit' => ($total_amount_with_gst['credit'] + ($this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') : 0)),
				'TaxGroup' => '',
			];
		}

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

			if ($invoice_item->serviceItem->taxCode && $KFC_IN == 0) {
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

			$service_invoice = $invoice_item->serviceInvoice()->with([
				'customer',
				'customer.primaryAddress',
				'branch',
				'branch.primaryAddress',
			])
				->first();
			// dump('start');
			// dd(1);
			if (!empty($service_invoice)) {
				if ($service_invoice->customer->primaryAddress->state_id) {
					if ($service_invoice->customer->primaryAddress->state_id == 3 && $service_invoice->branch->primaryAddress->state_id == 3) {
						if (empty($service_invoice->customer->gst_number)) {
							//FOR AXAPTA EXPORT WHILE GETING KFC ADD SEPERATE TAX LIKE CGST,SGST
							if (!empty($invoice_item->serviceItem->taxCode)) {
								foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
									//FOR CGST
									if ($tax->name == 'CGST') {
										$params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

										$params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
										$params['LedgerDimension'] = '7132' . '-' . $this->branch->code . '-' . $this->sbu->name;

										//REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
										$params['TVSHSNCode'] = $params['TVSSACCode'] = NULL;

										$this->exportRowToAxapta($params);
									}
									//FOR CGST
									if ($tax->name == 'SGST') {
										$params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

										$params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
										$params['LedgerDimension'] = '7432' . '-' . $this->branch->code . '-' . $this->sbu->name;

										//REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
										$params['TVSHSNCode'] = $params['TVSSACCode'] = NULL;

										$this->exportRowToAxapta($params);
									}
								}
								//FOR KFC
								if ($invoice_item->serviceItem->taxCode) {
									$params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;

									$params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
									$params['LedgerDimension'] = '2230' . '-' . $this->branch->code . '-' . $this->sbu->name;

									//REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
									$params['TVSHSNCode'] = $params['TVSSACCode'] = NULL;

									$this->exportRowToAxapta($params);
								}
							}
						}
					}
				}
			}
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
			'company_id' => $this->company_id,
			'entity_type_id' => 1400,
			'entity_id' => $this->id,
			'LedgerDimension' => $params['LedgerDimension'],
		]);

		$params['TVSHSNCode'] = isset($params['TVSHSNCode']) ? $params['TVSHSNCode'] : '';
		$export->CurrencyCode = 'INR';
		$export->JournalName = 'BPAS_NJV';
		$export->JournalNum = "";
		$export->Voucher = $params['Voucher'];
		$export->ApproverPersonnelNumber = $this->createdBy->employee->code;
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
		$export->TVSVendorLocationID = '';
		$export->TVSCustomerLocationID = $params['TVSHSNCode'] || $params['TVSSACCode'] ? $this->customer->axapta_location_id : '';
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
					}
					// else {
					// 	if (empty($record['Sub Category'])) {
					// 		$status['errors'][] = 'Sub Category is empty';
					// 	} else {
					// 		$sub_category = ServiceItemSubCategory::where([
					// 			'company_id' => $job->company_id,
					// 			'category_id' => $category->id,
					// 			'name' => $record['Sub Category'],
					// 		])->first();
					// 		if (!$sub_category) {
					// 			$status['errors'][] = 'Invalid Sub Category Or Sub Category is not mapped for this Category';
					// 		}
					// 	}
					// }
				}

				if (empty($record['Customer Code'])) {
					$status['errors'][] = 'Customer Code is empty';
				} else {
					$customer = Customer::where([
						'company_id' => $job->company_id,
						'code' => trim($record['Customer Code']),
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
						'code' => trim($record['Item Code']),
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
					$date = PHPExcel_Shared_Date::ExcelToPHP($record['Doc Date']);
					if (date('m', $date) > 3) {
						$document_date_year = date('Y', $date) + 1;
					} else {
						$document_date_year = date('Y', $date);
					}

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

				$taxes = [];
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
				$service_invoice->category_id = $category->id;
				// $service_invoice->sub_category_id = $sub_category->id;
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
				$item_taxes = [];
				$total_tax_amount = 0;
				if (!empty($item_code->sac_code_id)) {

					if ($service_invoice->customer->primaryAddress->state_id == $service_invoice->outlet->state_id) {
						$taxes = $service_invoice_item->serviceItem->taxCode->taxes()->where('type_id', 1160)->get();
					} else {
						$taxes = $service_invoice_item->serviceItem->taxCode->taxes()->where('type_id', 1161)->get();
					}

					// $tax_codes = TaxCode::with([
					// 	'taxes' => function ($query) use ($taxes) {
					// 		$query->whereIn('tax_id', $taxes['tax_ids']);
					// 	},
					// ])
					// 	->where('id', $item_code->sac_code_id)
					// 	->get();

					// if (!empty($tax_codes)) {
					// foreach ($tax_codes as $tax_code) {
					foreach ($taxes as $tax) {
						$tax_amount = round($service_invoice_item->sub_total * $tax->pivot->percentage / 100, 2);
						$total_tax_amount += $tax_amount;
						$item_taxes[$tax->id] = [
							'percentage' => $tax->pivot->percentage,
							'amount' => $tax_amount,
						];
					}
					$service_invoice_item->taxes()->sync($item_taxes);
					// }
				}
				// }
				// else {
				$KFC_tax_amount = 0;
				if ($service_invoice->customer->primaryAddress->state_id) {
					if (($service_invoice->customer->primaryAddress->state_id == 3) && ($service_invoice->outlet->state_id == 3)) {
						//3 FOR KERALA
						//check customer state and outlet states are equal KL.  //add KFC tax
						if (!$customer->gst_number) {
							//customer dont't have GST
							if (!empty($item_code->sac_code_id)) {
								//customer have HSN and SAC Code
								$KFC_tax_amount = round($service_invoice_item->sub_total * 1 / 100, 2); //ONE PERCENTAGE FOR KFC
								$item_taxes[4] = [ //4 for KFC
									'percentage' => 1,
									'amount' => $KFC_tax_amount,
								];
							}
						}
					}
					$service_invoice_item->taxes()->sync($item_taxes);
				}
				// }
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
			// dd(1);
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

	public function createPdf() {
		// dd('test');
		$r = $this->exportToAxapta();
		if (!$r['success']) {
			return $r;
		}

		$this->company->formatted_address = $this->company->primaryAddress ? $this->company->primaryAddress->getFormattedAddress() : 'NA';
		// $this->outlets->formatted_address = $this->outlets->primaryAddress ? $this->outlets->primaryAddress->getFormattedAddress() : 'NA';
		$this->outlets = $this->outlets ? $this->outlets : 'NA';
		$this->customer->formatted_address = $this->customer->primaryAddress ? $this->customer->primaryAddress->address_line1 : 'NA';
		// dd($this->outlets->formatted_address);
		$fields = Field::withTrashed()->get()->keyBy('id');
		if (count($this->serviceInvoiceItems) > 0) {
			$array_key_replace = [];
			foreach ($this->serviceInvoiceItems as $key => $serviceInvoiceItem) {
				$taxes = $serviceInvoiceItem->taxes;
				$type = $serviceInvoiceItem->serviceItem;
				foreach ($taxes as $array_key_replace => $tax) {
					$serviceInvoiceItem[$tax->name] = $tax;
				}
				//dd($type->sac_code_id);
			}
			//Field values
			$gst_total = 0;
			foreach ($this->serviceInvoiceItems as $key => $serviceInvoiceItem) {
				// dd($serviceInvoiceItem);
				$serviceInvoiceItem->eInvoiceUom;

				//FIELD GROUPS AND FIELDS INTEGRATION
				if (count($serviceInvoiceItem->eavVarchars) > 0) {
					$eav_varchar_field_group_ids = $serviceInvoiceItem->eavVarchars()->pluck('field_group_id')->toArray();
				} else {
					$eav_varchar_field_group_ids = [];
				}
				if (count($serviceInvoiceItem->eavInts) > 0) {
					$eav_int_field_group_ids = $serviceInvoiceItem->eavInts()->pluck('field_group_id')->toArray();
				} else {
					$eav_int_field_group_ids = [];
				}
				if (count($serviceInvoiceItem->eavDatetimes) > 0) {
					$eav_datetime_field_group_ids = $serviceInvoiceItem->eavDatetimes()->pluck('field_group_id')->toArray();
				} else {
					$eav_datetime_field_group_ids = [];
				}
				//GET UNIQUE FIELDGROUP IDs
				$field_group_ids = array_unique(array_merge($eav_varchar_field_group_ids, $eav_int_field_group_ids, $eav_datetime_field_group_ids));
				$field_group_val = [];
				if (!empty($field_group_ids)) {
					foreach ($field_group_ids as $fg_key => $fg_id) {
						// dump($fg_id);
						$fd_varchar_array = [];
						$fd_int_array = [];
						$fd_main_varchar_array = [];
						$fd_varchar_array = DB::table('eav_varchar')
							->where('entity_type_id', 1040)
							->where('entity_id', $serviceInvoiceItem->id)
							->where('field_group_id', $fg_id)
							->leftJoin('fields', 'fields.id', 'eav_varchar.field_id')
							->select('field_id as id', 'value', 'fields.name as field_name')
							->get()
							->toArray();
						$fd_datetimes = DB::table('eav_datetime')
							->where('entity_type_id', 1040)
							->where('entity_id', $serviceInvoiceItem->id)
							->where('field_group_id', $fg_id)
							->leftJoin('fields', 'fields.id', 'eav_datetime.field_id')
							->select('field_id as id', 'value', 'fields.name as field_name')
							->get()
							->toArray();
						$fd_datetime_array = [];
						if (!empty($fd_datetimes)) {
							foreach ($fd_datetimes as $fd_datetime_key => $fd_datetime_value) {
								//DATEPICKER
								if ($fields[$fd_datetime_value->id]->type_id == 7) {
									$fd_datetime_array[] = [
										'id' => $fd_datetime_value->id,
										'value' => date('d-m-Y', strtotime($fd_datetime_value->value)),
									];
								} elseif ($fields[$fd_datetime_value->id]->type_id == 8) {
									//DATETIMEPICKER
									$fd_datetime_array[] = [
										'id' => $fd_datetime_value->id,
										'value' => date('d-m-Y H:i:s', strtotime($fd_datetime_value->value)),
									];
								}
							}
						}
						$fd_ints = DB::table('eav_int')
							->where('entity_type_id', 1040)
							->where('entity_id', $serviceInvoiceItem->id)
							->where('field_group_id', $fg_id)
							->leftJoin('fields', 'fields.id', 'eav_int.field_id')
							->select(
								'field_id as id',
								'fields.name as field_name',
								DB::raw('GROUP_CONCAT(value) as value')
							)
							->groupBy('field_id')
							->get()
							->toArray();
						$fd_int_array = [];
						if (!empty($fd_ints)) {
							foreach ($fd_ints as $fd_int_key => $fd_int_value) {
								//MULTISELECT DROPDOWN
								if ($fields[$fd_int_value->id]->type_id == 2) {
									$fd_int_array[] = [
										'id' => $fd_int_value->id,
										'value' => explode(',', $fd_int_value->value),
									];
								} elseif ($fields[$fd_int_value->id]->type_id == 9) {
									//SWITCH
									$fd_int_array[] = [
										'id' => $fd_int_value->id,
										'value' => ($fd_int_value->value ? 'Yes' : 'No'),
									];
								} else {
									//OTHERS
									$fd_int_array[] = [
										'id' => $fd_int_value->id,
										'value' => $fd_int_value->value,
									];
								}
							}
						}
						$fd_main_varchar_array = array_merge($fd_varchar_array, $fd_int_array, $fd_datetime_array);
						//PUSH INDIVIDUAL FIELD GROUP TO ARRAY
						$field_group_val[] = [
							'id' => $fg_id,
							'fields' => $fd_main_varchar_array,
						];
					}
				}
				//PUSH TOTAL FIELD GROUPS
				$serviceInvoiceItem->field_groups = $field_group_val;
			}
		}
		//dd($this->type_id);
		$type = $serviceInvoiceItem->serviceItem;
		if (!empty($type->sac_code_id) && ($this->type_id == 1060)) {
			$this->sac_code_status = 'CREDIT NOTE(CRN)';
			$this->document_type = 'CRN';
		} elseif (empty($type->sac_code_id) && ($this->type_id == 1060)) {
			$this->sac_code_status = 'FINANCIAL CREDIT NOTE';
			$this->document_type = 'CRN';
		} elseif ($this->type_id == 1061) {
			$this->sac_code_status = 'Tax Invoice(DEN)';
			$this->document_type = 'DEN';
		} else {
			$this->sac_code_status = 'Invoice(INV)';
			$this->document_type = 'INV';
		}

		$this->qr_image = $this->qr_image ? base_path('storage/app/public/service-invoice/IRN_images/' . $this->qr_image) : NULL;
		$this->irn_number = $this->irn_number ? $this->irn_number : NULL;
		$this->ack_no = $this->ack_no ? $this->ack_no : NULL;
		$this->ack_date = $this->ack_date ? $this->ack_date : NULL;

		// dd($this->sac_code_status);
		//dd($serviceInvoiceItem->field_groups);
		$data = [];
		$data['service_invoice_pdf'] = $this;

		$tax_list = Tax::where('company_id', $this->company_id)->get();
		$data['tax_list'] = $tax_list;
		// dd($this->data['service_invoice_pdf']);
		$path = storage_path('app/public/service-invoice-pdf/');
		$pathToFile = $path . '/' . $this->number . '.pdf';
		File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

		$pdf = PDF::loadView('service-invoices/pdf/index', $data);
		// $po_file_name = 'Invoice-' . $this->number . '.pdf';
		File::delete($pathToFile);
		File::put($pathToFile, $pdf->output());
	}

	public static function percentage($num, $per) {
		return ($num / 100) * $per;
	}

	//LATER USE
	public function irnCreate($id) {
		// dd($id);
		$rsa = new Crypt_RSA;

		$public_key = 'MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAxqHazGS4OkY/bDp0oklL+Ser7EpTpxyeMop8kfBlhzc8dzWryuAECwu8i/avzL4f5XG/DdSgMz7EdZCMrcxtmGJlMo2tUqjVlIsUslMG6Cmn46w0u+pSiM9McqIvJgnntKDHg90EIWg1BNnZkJy1NcDrB4O4ea66Y6WGNdb0DxciaYRlToohv8q72YLEII/z7W/7EyDYEaoSlgYs4BUP69LF7SANDZ8ZuTpQQKGF4TJKNhJ+ocmJ8ahb2HTwH3Ol0THF+0gJmaigs8wcpWFOE2K+KxWfyX6bPBpjTzC+wQChCnGQREhaKdzawE/aRVEVnvWc43dhm0janHp29mAAVv+ngYP9tKeFMjVqbr8YuoT2InHWFKhpPN8wsk30YxyDvWkN3mUgj3Q/IUhiDh6fU8GBZ+iIoxiUfrKvC/XzXVsCE2JlGVceuZR8OzwGrxk+dvMnVHyauN1YWnJuUTYTrCw3rgpNOyTWWmlw2z5dDMpoHlY0WmTVh0CrMeQdP33D3LGsa+7JYRyoRBhUTHepxLwk8UiLbu6bGO1sQwstLTTmk+Z9ZSk9EUK03Bkgv0hOmSPKC4MLD5rOM/oaP0LLzZ49jm9yXIrgbEcn7rv82hk8ghqTfChmQV/q+94qijf+rM2XJ7QX6XBES0UvnWnV6bVjSoLuBi9TF1ttLpiT3fkCAwEAAQ=='; //PROVIDE FROM BDO COMPANY

		$clientid = "prakashr@featsolutions.in"; //PROVIDE FROM BDO COMPANY
		// dump('clientid ' . $clientid);

		$rsa->loadKey($public_key);
		$rsa->setEncryptionMode(2);
		$data = 'BBAkBDB0YzZiYThkYTg4ZDZBBDJjZBUyBGFkBBB0BWB='; // CLIENT SECRET KEY
		$ClientSecret = $rsa->encrypt($data);
		$clientsecretencrypted = base64_encode($ClientSecret);
		// dump('ClientSecret ' . $clientsecretencrypted);

		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$data = substr(str_shuffle($characters), 0, 32); // RANDOM KEY GENERATE
		// $data = 'Rdp5EB5w756dVph0C3jCXY1K6RPC6RCD'; // RANDOM KEY GENERATE
		$AppSecret = $rsa->encrypt($data);
		$appsecretkey = base64_encode($AppSecret);
		// dump('appsecretkey ' . $appsecretkey);

		$bdo_login_url = 'https://sandboxeinvoiceapi.bdo.in/bdoauth/bdoauthenticate';

		$ch = curl_init($bdo_login_url);
		// Setup request to send json via POST`
		$params = json_encode(array(
			'clientid' => $clientid,
			'clientsecretencrypted' => $clientsecretencrypted,
			'appsecretkey' => $appsecretkey,
		));

		// Attach encoded JSON string to the POST fields
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		// Set the content type to application/json
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

		// Return response instead of outputting
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Execute the POST request
		$server_output = curl_exec($ch);

		// Get the POST request header status
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// If header status is not Created or not OK, return error message
		if ($status != 200) {
			return [
				'success' => false,
				'errors' => curl_errno($ch),
			];
			// return response()->json([
			// 	'success' => false,
			// 	'error' => 'call to URL $bdo_login_url failed with status $status',
			// 	'errors' => ["response " . $server_output . ", curl_error " . curl_error($ch) . ", curl_errno " . curl_errno($ch)],
			// ]);
		}

		curl_close($ch);

		$server_output = json_decode($server_output);

		$expiry = $server_output->expiry;
		$bdo_authtoken = $server_output->bdo_authtoken;
		$status = $server_output->status;
		$bdo_sek = $server_output->bdo_sek;

		$aes_decrypt_url = 'https://www.devglan.com/online-tools/aes-decryption';

		$ch = curl_init($aes_decrypt_url);

		// Setup request to send json via POST`
		$params = json_encode(array(
			'textToDecrypt' => $bdo_sek,
			'secretKey' => $data,
			'mode' => 'ECB',
			'keySize' => '256',
			'dataFormat' => 'Base64',
		));

		// Attach encoded JSON string to the POST fields
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		// Set the content type to application/json
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		// Return response instead of outputting
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Execute the POST request
		$server_output = curl_exec($ch);

		// Get the POST request header status
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// If header status is not Created or not OK, return error message
		if ($status != 200) {
			return [
				'success' => false,
				'errors' => curl_errno($ch),
			];
			// return response()->json([
			// 	'success' => false,
			// 	'error' => 'call to URL $bdo_login_url failed with status $status',
			// 	'errors' => ["response " . $server_output . ", curl_error " . curl_error($ch) . ", curl_errno " . curl_errno($ch)],
			// ]);
		}

		curl_close($ch);

		$server_output = json_decode($server_output);

		$aes_decoded_plain_text = base64_decode($server_output->output);

		//ITEm
		$item = [];
		$sno = 1;
		foreach ($this->serviceInvoiceItems as $serviceInvoiceItem) {
			// dd($serviceInvoiceItem);

			//GET TAXES
			$cgst_total = 0;
			$sgst_total = 0;
			$igst_total = 0;
			$taxes = Tax::getTaxes($serviceInvoiceItem->service_item_id, $this->branch_id, $this->customer_id);
			if (!$taxes['success']) {
				return response()->json(['success' => false, 'error' => $taxes['error']]);
			}

			$service_item = ServiceItem::with([
				'coaCode',
				'taxCode',
				'taxCode.taxes' => function ($query) use ($taxes) {
					$query->whereIn('tax_id', $taxes['tax_ids']);
				},
			])
				->find($serviceInvoiceItem->service_item_id);
			if (!$service_item) {
				return response()->json(['success' => false, 'error' => 'Service Item not found']);
			}

			//TAX CALC AND PUSH
			if (!is_null($service_item->sac_code_id)) {
				if (count($service_item->taxCode->taxes) > 0) {
					foreach ($service_item->taxCode->taxes as $key => $value) {
						//FOR CGST
						if ($value->name == 'CGST') {
							$cgst_total += round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
						}
						//FOR CGST
						if ($value->name == 'SGST') {
							$sgst_total += round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
						}
						//FOR CGST
						if ($value->name == 'IGST') {
							$igst_total += round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
						}
					}
				}
			}

			$item['SlNo'] = $sno; //Statically assumed
			$item['PrdDesc'] = $serviceInvoiceItem->serviceItem->name;
			$item['IsServc'] = "Y"; //ALWAYS Y
			$item['HsnCd'] = $serviceInvoiceItem->serviceItem->taxCode ? $serviceInvoiceItem->serviceItem->taxCode->code : null;

			//BchDtls
			$item['BchDtls']["Nm"] = null;
			$item['BchDtls']["Expdt"] = null;
			$item['BchDtls']["wrDt"] = null;

			$item['Barcde'] = null;
			$item['Qty'] = 1; //ALWAYS 1
			$item['FreeQty'] = 0;
			$item['Unit'] = $serviceInvoiceItem->eInvoiceUom ? $serviceInvoiceItem->eInvoiceUom->code : "NOS";
			$item['UnitPrice'] = number_format($serviceInvoiceItem->rate ? $serviceInvoiceItem->rate : 0); //NEED TO CLARIFY
			$item['TotAmt'] = number_format($serviceInvoiceItem->sub_total ? $serviceInvoiceItem->sub_total : 0);
			$item['Discount'] = 0; //Always value will be "0"
			$item['PreTaxVal'] = number_format($serviceInvoiceItem->rate ? $serviceInvoiceItem->rate : 0);
			$item['AssAmt'] = number_format($serviceInvoiceItem->sub_total - 0);
			$item['IgstRt'] = number_format($serviceInvoiceItem->IGST ? $serviceInvoiceItem->IGST->pivot->percentage : 0);
			$item['IgstAmt'] = number_format($serviceInvoiceItem->sub_total * $serviceInvoiceItem->IGST->pivot->percentage / 100, 2);
			$item['CgstRt'] = number_format($serviceInvoiceItem->CGST ? $serviceInvoiceItem->CGST->pivot->percentage : 0, 2);
			$item['CgstAmt'] = number_format($serviceInvoiceItem->sub_total * $serviceInvoiceItem->CGST->pivot->percentage / 100);
			$item['SgstRt'] = number_format($serviceInvoiceItem->SGST ? $serviceInvoiceItem->SGST->pivot->percentage : 0, 2);
			$item['SgstAmt'] = number_format($serviceInvoiceItem->sub_total * $serviceInvoiceItem->SGST->pivot->percentage / 100);
			$item['CesRt'] = 0;
			$item['CesAmt'] = 0;
			$item['CesNonAdvlAmt'] = 0;
			$item['StateCesRt'] = 0; //NEED TO CLARIFY IF KFC
			$item['StateCesAmt'] = 0; //NEED TO CLARIFY IF KFC
			$item['StateCesNonAdvlAmt'] = 0; //NEED TO CLARIFY IF KFC
			$item['OthChrg'] = 0;
			$item['TotItemVal'] = number_format(($serviceInvoiceItem->sub_total ? $serviceInvoiceItem->sub_total : 0) + ($serviceInvoiceItem->sub_total * $serviceInvoiceItem->IGST->pivot->percentage / 100) + ($serviceInvoiceItem->sub_total * $serviceInvoiceItem->CGST->pivot->percentage / 100) + ($serviceInvoiceItem->sub_total * $serviceInvoiceItem->SGST->pivot->percentage / 100), 2);
			$item['OrdLineRef'] = "0";
			$item['OrgCntry'] = "IN"; //Always value will be "IND"
			$item['PrdSlNo'] = null;

			//AttribDtls
			$item['AttribDtls'][] = [
				"Nm" => null,
				"Val" => null,
			];

			$sno++;

		}

		//EGST
		//NO DATA GIVEN IN WORD DOC START
		$item['EGST']['nilrated_amt'] = null;
		$item['EGST']['exempted_amt'] = null;
		$item['EGST']['non_gst_amt'] = null;
		$item['EGST']['reason'] = null;
		$item['EGST']['debit_gl_id'] = null;
		$item['EGST']['debit_gl_name'] = null;
		$item['EGST']['credit_gl_id'] = null;
		$item['EGST']['credit_gl_name'] = null;
		$item['EGST']['sublocation'] = null;
		//NO DATA GIVEN IN WORD DOC END

		//RefDtls BELLOW
		//PrecDocDtls
		$prodoc_detail = [];
		$prodoc_detail['InvNo'] = $this->e_invoice_date ? $this->e_invoice_date : null; //no DATA ?
		$prodoc_detail['InvDt'] = null; //no DATA ?
		$prodoc_detail['OthRefNo'] = null; //no DATA ?
		//ContrDtls
		$control_detail = [];
		$control_detail['RecAdvRefr'] = null; //no DATA ?
		$control_detail['RecAdvDt'] = null; //no DATA ?
		$control_detail['Tendrefr'] = null; //no DATA ?
		$control_detail['Contrrefr'] = null; //no DATA ?
		$control_detail['Extrefr'] = null; //no DATA ?
		$control_detail['Projrefr'] = null;
		$control_detail['Porefr'] = null;
		$control_detail['PoRefDt'] = null;

		//AddlDocDtls
		$additionaldoc_detail = [];
		$additionaldoc_detail['Url'] = null;
		$additionaldoc_detail['Docs'] = null;
		$additionaldoc_detail['Info'] = null;

		$positive_negative_sign = $this->type_id == 1060 ? '+' : '-';

		// dd($cgst_total, $sgst_total, $igst_total);
		$json_encoded_data =
			json_encode(
			array(
				'TranDtls' => array(
					'TaxSch' => "GST",
					'SupTyp' => "B2B",
					'RegRev' => $this->is_e_reverse_charge_applicable == 1 ? "Y" : "N",
					'EcmGstin' => null,
					'IgstonIntra' => null, //NEED TO CLARIFY
				),
				'DocDtls' => array(
					"Typ" => $this->type_id == 1060 ? 'CRN' : 'DBN',
					// "No" => $this->number,
					"No" => '23AUG2020SN90',
					"Dt" => date('d-m-Y', strtotime($this->document_date)),
				),
				'SellerDtls' => array(
					// "Gstin" => $this->outlets ? ($this->outlets->gst_number ? $this->outlets->gst_number : 'N/A') : 'N/A',
					"Gstin" => "09ADDPT0274H009",
					"LglNm" => $this->outlets ? $this->outlets->name : 'N/A',
					"TrdNm" => $this->outlets ? $this->outlets->name : 'N/A',
					"Addr1" => $this->outlets->primaryAddress ? $this->outlets->primaryAddress->address_line1 : 'N/A',
					"Addr2" => $this->outlets->primaryAddress ? $this->outlets->primaryAddress->address_line2 : null,
					"Loc" => $this->outlets->primaryAddress ? ($this->outlets->primaryAddress->state ? $this->outlets->primaryAddress->state->name : 'N/A') : 'N/A',
					// "Pin" => $this->outlets->primaryAddress ? $this->outlets->primaryAddress->pincode : 'N/A',
					// "Stcd" => $this->outlets->primaryAddress ? ($this->outlets->primaryAddress->state ? $this->outlets->primaryAddress->state->e_invoice_state_code : 'N/A') : 'N/A',
					"Pin" => 561105,
					"Stcd" => "09",
					"Ph" => null, //need to clarify
					"Em" => null, //need to clarify
				),
				"BuyerDtls" => array(
					// 	// "Gstin" => $this->customer->gst_number ? $this->customer->gst_number : 'N/A', //need to clarify if available ok otherwise ?
					"Gstin" => "27AABCT3518Q1ZW",
					"LglNm" => $this->customer ? $this->customer->name : 'N/A',
					"TrdNm" => $this->customer ? $this->customer->name : null,
					"Pos" => $this->customer->primaryAddress ? ($this->customer->primaryAddress->state ? $this->customer->primaryAddress->state->e_invoice_state_code : 'N/A') : 'N/A',
					// "Pos" => "27",
					"Loc" => $this->customer->primaryAddress ? ($this->customer->primaryAddress->state ? $this->customer->primaryAddress->state->name : 'N/A') : 'N/A',

					"Addr1" => $this->customer->primaryAddress ? $this->customer->primaryAddress->address_line1 : 'N/A',
					"Addr2" => $this->customer->primaryAddress ? $this->customer->primaryAddress->address_line2 : null,
					// "Pin" => $this->customer->primaryAddress ? $this->customer->primaryAddress->pincode : null,
					// "Stcd" => $this->customer->primaryAddress ? ($this->customer->primaryAddress->state ? $this->customer->primaryAddress->state->e_invoice_state_code : null) : null,
					"Pin" => 400099,
					"Stcd" => "27",
					"Ph" => $this->customer->mobile_no ? $this->customer->mobile_no : null,
					"Em" => $this->customer->email ? $this->customer->email : null,
				),
				// 'BuyerDtls' => array(
				'DispDtls' => array(
					"Nm" => null,
					"Addr1" => null,
					"Addr2" => null,
					"Loc" => null,
					"Pin" => null,
					"Stcd" => null,
				),
				'ShipDtls' => array(
					"Gstin" => null,
					"LglNm" => null,
					"TrdNm" => null,
					"Addr1" => null,
					"Addr2" => null,
					"Loc" => null,
					"Pin" => null,
					"Stcd" => null,
				),
				'ItemList' => array(
					'Item' => array(
						$item,
					),
				),
				'ValDtls' => array(
					"AssVal" => number_format($this->amount_total ? $this->amount_total : 0),
					"CgstVal" => number_format($cgst_total),
					"SgstVal" => number_format($sgst_total),
					"IgstVal" => number_format($igst_total),
					"CesVal" => 0,
					"StCesVal" => 0,
					"Discount" => 0,
					"OthChrg" => 0,
					"RndOffAmt" => number_format($this->e_round_off_amount - $this->total),
					// "RndOffAmt" => 0, // Invalid invoice round off amount ,should be  + or - RS 10.
					"TotInvVal" => number_format($this->e_round_off_amount),
					"TotInvValFc" => null,
				),
				"PayDtls" => array(
					"Nm" => null,
					"Accdet" => null,
					"Mode" => null,
					"Fininsbr" => null,
					"Payterm" => null, //NO DATA
					"Payinstr" => null, //NO DATA
					"Crtrn" => null, //NO DATA
					"Dirdr" => null, //NO DATA
					"Crday" => 0, //NO DATA
					"Paidamt" => 0, //NO DATA
					"Paymtdue" => 0, //NO DATA
				),
				"RefDtls" => array(
					"InvRm" => null,
					"DocPerdDtls" => array(
						"InvStDt" => null,
						"InvEndDt" => null,
					),
					"PrecDocDtls" => [
						$prodoc_detail,
					],
					"ContrDtls" => [
						$control_detail,
					],
				),
				"AddlDocDtls" => [
					$additionaldoc_detail,
				],
				"ExpDtls" => array(
					"ShipBNo" => null,
					"ShipBDt" => null,
					"Port" => null,
					"RefClm" => null,
					"ForCur" => null,
					"CntCode" => null, // ALWAYS IND //// ERROR : For Supply type other than EXPWP and EXPWOP, country code should be blank
					"ExpDuty" => null,
				),
				"EwbDtls" => array(
					"Transid" => null,
					"Transname" => null,
					"Distance" => null,
					"Transdocno" => null,
					"TransdocDt" => null,
					"Vehno" => null,
					"Vehtype" => null,
					"TransMode" => null,
				),
			)
		);

		// dd($json_encoded_data);

		//AES ENCRYPT
		$aes_encrypt_url = 'https://www.devglan.com/online-tools/aes-encryption';

		$ch = curl_init($aes_encrypt_url);

		$data = array(
			'data' => json_encode(array(
				'textToEncrypt' => $json_encoded_data,
				'secretKey' => $aes_decoded_plain_text,
				'mode' => 'ECB',
				'keySize' => '256',
				'dataFormat' => 'Base64',
			)),
		);

		// Attach encoded JSON string to the POST fields
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

		// Set the content type to application/json
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:multipart/form-data'));
		// curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

		// Return response instead of outputting
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$server_output = curl_exec($ch);

		// Get the POST request header status
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// If header status is not Created or not OK, return error message
		if ($status != 200) {
			return [
				'success' => false,
				'errors' => curl_errno($ch),
			];
			// return response()->json([
			// 	'error' => 'call to URL $aes_encrypt_url failed with status $status',
			// 	'errors' => ["response " . $server_output . ", curl_error " . curl_error($ch) . ", curl_errno " . curl_errno($ch)],
			// ]);
		}

		// dd(storage_path('app/public/service-invoice/IRN_images/'));

		curl_close($ch);

		$aes_output = json_decode($server_output);
		// dd($aes_output->output);

		//ENCRYPTED GIVEN DATA TO DBO
		$bdo_generate_irn_url = 'https://sandboxeinvoiceapi.bdo.in/bdoapi/public/generateIRN';

		$ch = curl_init($bdo_generate_irn_url);
		// Setup request to send json via POST`
		$params = json_encode(array(
			'Data' => $aes_output->output,
		));

		// Attach encoded JSON string to the POST fields
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		// Set the content type to application/json
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'client_id: ' . $clientid,
			'bdo_authtoken: ' . $bdo_authtoken,
			'action: GENIRN',
		));

		// Return response instead of outputting
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Execute the POST request
		$generate_irn_output = curl_exec($ch);
		// dump($generate_irn_output);

		curl_close($ch);

		$generate_irn_output = json_decode($generate_irn_output, true);
		// dump($generate_irn_output);
		// dd();

		// If header status is not Created or not OK, return error message
		if (is_array($generate_irn_output['Error'])) {
			$bdo_errors = [];
			$rearrange_key = 0;
			foreach ($generate_irn_output['Error'] as $key => $error) {
				// dump($rearrange_key, $error);
				$bdo_errors[$rearrange_key] = $error;
				$rearrange_key++;
			}
			// dump($bdo_errors);
			return [
				'success' => false,
				'errors' => $bdo_errors,
			];
			// return response()->json(['success' => false, 'errors' => $bdo_errors]);
			// dd('Error: ' . $generate_irn_output['Error']['E2000']);
		} elseif (!is_array($generate_irn_output['Error'])) {
			if ($generate_irn_output['Status'] != 1) {
				return [
					'success' => false,
					'errors' => $generate_irn_output['Error'],
				];
				// dd('Error: ' . $generate_irn_output['Error']);
			}
		}

		//AES DECRYPTION AFTER GENERATE IRN
		$aes_decrypt_url = 'https://www.devglan.com/online-tools/aes-decryption';

		$ch = curl_init($aes_decrypt_url);

		// Setup request to send json via POST`
		$params = json_encode(array(
			'textToDecrypt' => $generate_irn_output['Data'],
			'secretKey' => $aes_decoded_plain_text, //PLAIN TEXT GET FROM DECODE
			'mode' => 'ECB',
			'keySize' => '256',
			'dataFormat' => 'Base64',
		));

		// Attach encoded JSON string to the POST fields
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		// Set the content type to application/json
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

		// Return response instead of outputting
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Execute the POST request
		$server_output = curl_exec($ch);
		// dump($server_output);

		// Get the POST request header status
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		// dump('final status check: ' . $status);
		// If header status is not Created or not OK, return error message
		if ($status != 200) {
			return [
				'success' => false,
				'errors' => curl_errno($ch),
			];
			// return response()->json([
			// 	'success' => false,
			// 	'error' => 'call to URL $bdo_generate_irn_url failed with status $status',
			// 	'errors' => ["response " . $server_output . ", curl_error " . curl_error($ch) . ", curl_errno " . curl_errno($ch)],
			// ]);
		}

		curl_close($ch);

		$final_encrypt_output = json_decode($server_output);

		$aes_final_decoded_plain_text = base64_decode($final_encrypt_output->output);
		// dump($aes_final_decoded_plain_text);
		$final_json_decode = json_decode($aes_final_decoded_plain_text);

		$IRN_images_des = storage_path('app/public/service-invoice/IRN_images');
		File::makeDirectory($IRN_images_des, $mode = 0777, true, true);

		$url = QRCode::text($final_json_decode->SignedQRCode)->setSize(4)->setOutfile('storage/app/public/service-invoice/IRN_images/' . $this->number . '.png')->png();

		// $image = '<img src="storage/app/public/service-invoice/IRN_images/' . $final_json_decode->AckNo . '.png" title="IRN QR Image">';
		$service_invoice_update = self::find($this_id);
		$service_invoice_update->irn_number = $final_json_decode->Irn;
		$service_invoice_update->qr_image = $this->number . '.png';
		$service_invoice_update->irn_response = $server_output;
		$service_invoice_update->save();

		// $this_pdf->qr_image = $this->number . '.png';
	}
}
