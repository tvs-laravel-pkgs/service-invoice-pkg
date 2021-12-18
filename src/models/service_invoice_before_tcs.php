<?php

namespace Abs\ServiceInvoicePkg;
use Abs\AttributePkg\Models\Field;
use Abs\AxaptaExportPkg\AxaptaExport;
use Abs\ImportCronJobPkg\ImportCronJob;
use Abs\SerialNumberPkg\SerialNumberGroup;
use Abs\TaxPkg\Tax;
use Abs\TaxPkg\TaxCode;
use App\Address;
use App\ApiLog;
use App\City;
use App\Company;
use App\Config;
use App\Customer;
use App\EInvoiceUom;
use App\Entity;
use App\FinancialYear;
use App\Outlet;
use App\Sbu;
use App\State;
use App\Vendor;
use DB;
use File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPExcel_IOFactory;
use PHPExcel_Shared_Date;

class ServiceInvoiceOld extends Model {
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
		return $this->attributes['ack_date'] = empty($date) ? NULL : date('d-m-Y H:i:s', strtotime($date));
	}

	public function serviceItemSubCategory() {
		return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceItemSubCategory', 'sub_category_id', 'id');
	}

	public function serviceItemCategory() {
		return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceItemCategory', 'category_id', 'id');
	}

	public function toAccountType() {
		return $this->belongsTo('App\Config', 'to_account_type_id');
	}

	public function customer() {
		if ($this->to_account_type_id == 1440) {
			//customer
			return $this->belongsTo('Abs\CustomerPkg\Customer', 'customer_id');
		} elseif ($this->to_account_type_id == 1441) {
			//vendor
			return $this->belongsTo('App\Vendor', 'customer_id');
		}
		// elseif ($this->to_account_type_id == 1442) {
		// 	//ledger
		// 	return $this->belongsTo('Abs\JVPkg\Ledger', 'customer_id');
		// }
	}

	// public function customer() {
	// return $this->belongsTo('App\Customer', 'customer_id', 'id');
	// }

	public function branch() {
		return $this->belongsTo('App\Outlet', 'branch_id', 'id');
	}

	public function address() {
		return $this->belongsTo('App\Address', 'address_id', 'id');
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
		$total_amount_with_gst['invoice'] = 0;
		$KFC_IN = 0;
		foreach ($this->serviceInvoiceItems as $invoice_item) {
			$service_invoice = $invoice_item->serviceInvoice()->with([
				'toAccountType',
				// 'customer',
				// 'customer.primaryAddress',
				'branch',
				'branch.primaryAddress',
			])
				->first();

			$service_invoice->customer;
			$service_invoice->address;
			// dd($service_invoice->address);
			$service_invoice->customer->primaryAddress;

			if (!empty($service_invoice)) {
				if ($service_invoice->customer->primaryAddress->state_id || $service_invoice->address->state_id) {
					if (($service_invoice->customer->primaryAddress->state_id == 3 || $service_invoice->address->state_id == 3) && $service_invoice->branch->primaryAddress->state_id == 3) {
						if (empty($service_invoice->customer->gst_number) || empty($service_invoice->customer->gst_number)) {
							if (!empty($invoice_item->serviceItem->taxCode)) {
								$KFC_IN = 1;
								foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
									if ($tax->name == 'CGST') {
										$total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

										$total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

										$total_amount_with_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

									}
									//FOR CGST
									if ($tax->name == 'SGST') {
										$total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

										$total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

										$total_amount_with_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
									}
								}
								//FOR KFC
								if ($invoice_item->serviceItem->taxCode) {
									$total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;

									$total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;

									$total_amount_with_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
								}
							}
						}
					}
				}
			}
			$item_codes[] = $invoice_item->serviceItem->code;
			$item_descriptions[] = $invoice_item->description;
		}
		// dd($total_amount_with_gst['credit'], $total_amount_with_gst['debit'], $total_amount_with_gst['invoice']);

		$Txt = implode(',', $item_descriptions);
		if ($this->type_id == 1060) {
			//CN
			$Txt .= ' - Credit note for ';
		} elseif ($this->type_id == 1061) {
			//DN
			$Txt .= ' - Debit note for ';
		} elseif ($this->type_id == 1062) {
			//INV
			$Txt .= ' - Invoice for ';
		}
		$Txt .= implode(',', $item_codes);

		// dump($Txt);
		// dump($this->serviceInvoiceItems()->sum('sub_total'));
		$amount_diff = 0;
		if (!empty($this->final_amount) && !empty($this->total)) {
			$amount_diff = number_format(($this->final_amount - $this->total), 2);
		}

		// dump($amount_diff);

		if ($total_amount_with_gst['debit'] == 0 && $total_amount_with_gst['credit'] == 0 && $total_amount_with_gst['invoice'] == 0) {
			// dump('if');
			$params = [
				'Voucher' => 'V',
				'AccountType' => $this->to_account_type_id == 1440 ? 'Customer' : 'Vendor',
				'LedgerDimension' => $this->customer->code,
				'Txt' => $Txt . '-' . $this->number,
				// 'AmountCurDebit' => ($this->type_id == 1061 || $this->type_id == 1062) ? $this->serviceInvoiceItems()->sum('sub_total') : 0,
				'TaxGroup' => '',
			];
			//ADDED FOR ROUND OFF
			if ($amount_diff > 0) {
				// dump('if');
				$params['AmountCurCredit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff : 0;
				if ($this->type_id == 1061) {
					$params['AmountCurDebit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff : 0;

				} elseif ($this->type_id == 1062) {
					$params['AmountCurDebit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff : 0;
				} else {
					$params['AmountCurDebit'] = '';
				}
			} else {
				// dump('else');
				$params['AmountCurCredit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff : 0;
				if ($this->type_id == 1061) {
					$params['AmountCurDebit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff : 0;

				} elseif ($this->type_id == 1062) {
					$params['AmountCurDebit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff : 0;
				} else {
					$params['AmountCurDebit'] = '';
				}
			}
		} else {
			// dump('else');
			$params = [
				'Voucher' => 'V',
				'AccountType' => $this->to_account_type_id == 1440 ? 'Customer' : 'Vendor',
				'LedgerDimension' => $this->customer->code,
				'Txt' => $Txt . '-' . $this->number,
				// 'AmountCurDebit' => $this->type_id == 1061 ? ($total_amount_with_gst['debit'] + ($this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') : 0)) : 0,
				'TaxGroup' => '',
			];
			//ADDED FOR ROUND OFF
			if ($amount_diff > 0) {
				// dump('if');
				if ($this->type_id == 1061) {
					$params['AmountCurDebit'] = $this->type_id == 1061 ? ($total_amount_with_gst['debit'] + ($this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff : 0)) : 0;
				} elseif ($this->type_id == 1062) {
					$params['AmountCurDebit'] = $this->type_id == 1062 ? ($total_amount_with_gst['invoice'] + ($this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff : 0)) : 0;
				} else {
					$params['AmountCurDebit'] = '';
				}
				$params['AmountCurCredit'] = ($total_amount_with_gst['credit'] + ($this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff : 0));
			} else {
				// dump('else');
				if ($this->type_id == 1061) {
					$params['AmountCurDebit'] = $this->type_id == 1061 ? ($total_amount_with_gst['debit'] + ($this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : 0)) : 0;

				} elseif ($this->type_id == 1062) {
					$params['AmountCurDebit'] = $this->type_id == 1062 ? ($total_amount_with_gst['invoice'] + ($this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : 0)) : 0;
				} else {
					$params['AmountCurDebit'] = '';
				}
				$params['AmountCurCredit'] = ($total_amount_with_gst['credit'] + ($this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : 0));
			}
		}
		// dd($params);
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
				// 'AmountCurCredit' => $this->type_id == 1061 ? $invoice_item->sub_total : 0,
				'TaxGroup' => '',
				// 'TVSSACCode' => ($invoice_item->serviceItem->taxCode != null) ? $invoice_item->serviceItem->taxCode->code : NULL,
			];
			if ($this->type_id == 1061) {
				$params['AmountCurCredit'] = $this->type_id == 1061 ? $invoice_item->sub_total : 0;
			} elseif ($this->type_id == 1062) {
				$params['AmountCurCredit'] = $this->type_id == 1062 ? $invoice_item->sub_total : 0;
			} else {
				$params['AmountCurCredit'] = '';
			}

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
			// dump($params);
			$this->exportRowToAxapta($params);

			$service_invoice = $invoice_item->serviceInvoice()->with([
				'toAccountType',
				// 'customer',
				// 'customer.primaryAddress',
				'branch',
				'branch.primaryAddress',
			])
				->first();

			$service_invoice->customer;
			$service_invoice->customer->primaryAddress;
			// dump($service_invoice);
			// dd(1);
			if (!empty($service_invoice)) {
				if ($service_invoice->customer->primaryAddress->state_id || $service_invoice->address->state_id) {
					if (($service_invoice->customer->primaryAddress->state_id == 3 || $service_invoice->address->state_id == 3) && $service_invoice->branch->primaryAddress->state_id == 3) {
						if (empty($service_invoice->customer->gst_number) || empty($service_invoice->address->state_id)) {
							//FOR AXAPTA EXPORT WHILE GETING KFC ADD SEPERATE TAX LIKE CGST,SGST
							if (!empty($invoice_item->serviceItem->taxCode)) {
								foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
									//FOR CGST
									if ($tax->name == 'CGST') {
										if ($this->type_id == 1061) {
											$params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
										} elseif ($this->type_id == 1062) {
											$params['AmountCurCredit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
										} else {
											$params['AmountCurCredit'] = '';
										}

										$params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
										$params['LedgerDimension'] = '7132' . '-' . $this->branch->code . '-' . $this->sbu->name;

										//REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
										$params['TVSHSNCode'] = $params['TVSSACCode'] = NULL;
										$this->exportRowToAxapta($params);
									}
									//FOR CGST
									if ($tax->name == 'SGST') {
										if ($this->type_id == 1061) {
											$params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
										} elseif ($this->type_id == 1062) {
											$params['AmountCurCredit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
										} else {
											$params['AmountCurCredit'] = '';
										}

										$params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
										$params['LedgerDimension'] = '7432' . '-' . $this->branch->code . '-' . $this->sbu->name;

										//REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
										$params['TVSHSNCode'] = $params['TVSSACCode'] = NULL;

										$this->exportRowToAxapta($params);
									}
								}
								//FOR KFC
								if ($invoice_item->serviceItem->taxCode) {
									if ($this->type_id == 1061) {
										$params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
									} elseif ($this->type_id == 1062) {
										$params['AmountCurCredit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
									} else {
										$params['AmountCurCredit'] = '';
									}
									$params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
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
		if (!empty($service_invoice->round_off_amount) && $service_invoice->round_off_amount != '0.00') {
			if ($amount_diff > 0) {
				if ($this->type_id == 1061) {
					$params['AmountCurDebit'] = $this->type_id == 1061 ? $amount_diff : "";
				} elseif ($this->type_id == 1062) {
					$params['AmountCurDebit'] = $this->type_id == 1062 ? $amount_diff : "";
				} else {
					$params['AmountCurDebit'] = '';
				}
				$params['AmountCurCredit'] = $this->type_id == 1060 ? $amount_diff : "";
				$params['LedgerDimension'] = '3198' . '-' . $this->branch->code . '-' . $this->sbu->name;

				$this->exportRowToAxapta($params);
			} else {
				if ($this->type_id == 1061) {
					$params['AmountCurCredit'] = $this->type_id == 1061 ? ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : "";
				} elseif ($this->type_id == 1062) {
					$params['AmountCurCredit'] = $this->type_id == 1062 ? ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : "";
				} else {
					$params['AmountCurCredit'] = '';
				}
				$params['AmountCurDebit'] = $this->type_id == 1060 ? ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : "";
				$params['LedgerDimension'] = '3198' . '-' . $this->branch->code . '-' . $this->sbu->name;

				$this->exportRowToAxapta($params);
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
			$i = 0;
			foreach ($rows as $k => $row) {
				$record = [];
				foreach ($header as $key => $column) {
					if (!$column) {
						continue;
					} else {
						$record[$column] = trim($row[$key]);
					}
				}
				if (empty($record['SNO'])) {
					// exit;
				} else {
					// dump('first Sheet');
					// dump($record['SNO']);

					$original_record = $record;
					$status = [];
					$status['errors'] = [];

					if (empty($record['SNO'])) {
						$status['errors'][] = 'SNO is empty';
					} else {
						$sno = intval($record['SNO']);
						if (!$sno) {
							$status['errors'][] = 'Invalid SNO';
						}
					}

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
						} else {
							$doc_date = $record['Doc Date'];
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

					if (empty($record['Is Service'])) {
						$status['errors'][] = 'Is Service is empty';
					} else {
						if ($record['Is Service'] == 'Yes') {
							$is_service = 1;
						} elseif ($record['Is Service'] == 'No') {
							$is_service = 0;
						} elseif ($record['Is Service'] == 'Non-Taxable') {
							$is_service = 2;
						}
						if (!$is_service) {
							$status['errors'][] = 'Invalid Service';
						}
					}

					if (empty($record['Reverse Charge Applicable'])) {
						$status['errors'][] = 'Reverse Charge Applicable is empty';
					} else {
						if ($record['Reverse Charge Applicable'] == 'Yes') {
							$is_reverse_charge_applicable = 1;
						} else {
							$is_reverse_charge_applicable = 0;
						}
						if (!$is_reverse_charge_applicable) {
							$status['errors'][] = 'Invalid Reverse Charge Applicable';
						}
					}

					if (empty($record['Reverse Charge Applicable'])) {
						$status['errors'][] = 'Reverse Charge Applicable is empty';
					} else {
						if ($record['Reverse Charge Applicable'] == 'Yes') {
							$is_reverse_charge_applicable = 1;
						} else {
							$is_reverse_charge_applicable = 0;
						}
						if (!$is_reverse_charge_applicable) {
							$status['errors'][] = 'Invalid Reverse Charge Applicable';
						}
					}

					$po_reference_number = !empty($record['PO Reference Number']) ? $record['PO Reference Number'] : NULL;
					// dd($record);
					$reference_invoice_number = !empty($record['Reference Invoice Number']) ? $record['Reference Invoice Number'] : NULL;
					$reference_invoice_date = !empty($record['Reference Invoice Date']) ? date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($reference_invoice_date)) : NULL;
					// dump($po_reference_number, $reference_invoice_date, $reference_invoice_number);
					// dd();

					if (empty($record['To Account Type'])) {
						$status['errors'][] = 'To Account Type is empty';
					} else {
						if ($record['To Account Type'] == 'Customer' || $record['To Account Type'] == 'customer') {
							$to_account_type_id = 1440;
						} elseif ($record['To Account Type'] == 'Vendor' || $record['To Account Type'] == 'vendor') {
							$to_account_type_id = 1441;
						}
						if (!$to_account_type_id) {
							$status['errors'][] = 'Invalid To Account Type';
						}
					}
					// dump($to_account_type_id . 'to_account_type_id');
					// dump($record);
					// dump($record['Customer/Vendor Code']);
					if (empty($record['Customer/Vendor Code'])) {
						$status['errors'][] = 'Customer Code is empty';
					} else {
						$customer = '';
						if ($to_account_type_id == 1440) {
							$customer = Customer::where([
								'company_id' => $job->company_id,
								'code' => trim($record['Customer/Vendor Code']),
							])->first();
							if (!$customer) {
								$status['errors'][] = 'Invalid Customer';
							}
							if ($customer->id) {
								$customer_address = Address::where([
									'company_id' => $job->company_id,
									'entity_id' => $customer->id,
									'address_of_id' => 24, //CUSTOMER
								])
									->orderBy('id', 'desc')
									->first();
							}
							if (!$customer_address) {
								$status['errors'][] = 'Address Not Mapped with Customer';
							}
						} elseif ($to_account_type_id == 1441) {
							$customer = Vendor::where([
								'company_id' => $job->company_id,
								'code' => trim($record['Customer/Vendor Code']),
							])->first();
							if (!$customer) {
								$status['errors'][] = 'Invalid Vendor';
							}
							if ($customer->id) {
								$vendor_address = Address::where([
									'company_id' => $job->company_id,
									'entity_id' => $customer->id,
									'address_of_id' => 21, //VENDOR
								])
									->orderBy('id', 'desc')
									->first();
							}
							if (!$vendor_address) {
								$status['errors'][] = 'Address Not Mapped with Vendor';
							}
						}
					}
					// dump($customer->id . 'customer_id');

					//GET FINANCIAL YEAR ID BY DOCUMENT DATE
					try {
						$date = PHPExcel_Shared_Date::ExcelToPHP($record['Doc Date']);
						if (date('m', $date) > 3) {
							$document_date_year = date('Y', $date) + 1;
						} else {
							$document_date_year = date('Y', $date);
						}

						$financial_year = FinancialYear::where('from', $document_date_year)
							// ->where('company_id', $job->company_id)
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
						} elseif ($type->id == 1062) {
							//INV
							$serial_number_category = 125;
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
					// dd($customer->id);

					//STATICALLY GET SECOND SHEET FROM EXCEL
					$objPHPExcel = PHPExcel_IOFactory::load(storage_path('app/' . $job->src_file));
					$sheet = $objPHPExcel->getSheet(1);
					$highestRow = $sheet->getHighestDataRow();

					$header = $sheet->rangeToArray('A1:F1', NULL, TRUE, FALSE);
					$header = $header[0];
					$rows = $sheet->rangeToArray('A2:F2' . $highestRow, NULL, TRUE, FALSE);

					$amount_total = 0;
					$sub_total = 0;
					$total = 0;
					$invoice_amount = 0;

					foreach ($rows as $k => $row) {
						$item_record = [];
						foreach ($header as $key => $column) {
							if (!$column) {
								continue;
							} else {
								$item_record[$column] = trim($row[$key]);
							}
						}
						//Check Row Empty or not
						if (count(array_filter($row)) == 0) {
							// $status['errors'][] = 'Row is empty';
							continue;

						} else {
							// dump($customer->id);
							// dump('2 Sheet');

							if ($item_record['SNO'] == $sno) {
								// dump($item_record['SNO']);
								// dump($sno);

								$original_record = $item_record;
								$status = [];
								$status['errors'] = [];
								// dd($item_record);
								if (empty($item_record['SNO'])) {
									$status['errors'][] = 'SNO is empty';
								} else {
									$item_sno = intval($item_record['SNO']);
									if (!$item_sno) {
										$status['errors'][] = 'Invalid SNO';
									}
								}

								if (empty($item_record['Item Code'])) {
									$status['errors'][] = 'Item Code is empty';
								} else {
									$item_code = ServiceItem::where([
										'company_id' => $job->company_id,
										'code' => trim($item_record['Item Code']),
									])->first();
									if (!$item_code) {
										$status['errors'][] = 'Invalid Item Code';
									}
								}

								if (empty($item_record['UOM'])) {
									$status['errors'][] = 'UOM is empty';
								} else {
									$uom = EInvoiceUom::where([
										'company_id' => $job->company_id,
										'code' => trim($item_record['UOM']),
									])->first();
									if (!$uom) {
										$status['errors'][] = 'Invalid UOM';
									}
								}

								if (empty($item_record['Reference'])) {
									$status['errors'][] = 'Reference is empty';
								}

								if (empty($item_record['Quantity'])) {
									$status['errors'][] = 'Quantity is empty';
								}

								if (empty($item_record['Amount'])) {
									$status['errors'][] = 'Amount is empty';
								} elseif (!is_numeric($item_record['Amount'])) {
									$status['errors'][] = 'Invalid Amount';
								}

								$taxes = [];
								if ($item_code && $branch && $customer) {
									$taxes = Tax::getTaxes($item_code->id, $branch->id, $customer->id, $to_account_type_id);
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
								// dd(date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($doc_date)));
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
								} elseif ($type->id == 1062) {
									$service_invoice->is_cn_created = 0;
								}

								$service_invoice->company_id = $job->company_id;
								$service_invoice->type_id = $type->id;
								$service_invoice->branch_id = $branch->id;
								$service_invoice->sbu_id = $sbu->id;
								$service_invoice->category_id = $category->id;
								// $service_invoice->sub_category_id = $sub_category->id;
								$service_invoice->document_date = date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($doc_date));
								$service_invoice->is_service = $is_service;
								$service_invoice->is_reverse_charge_applicable = $is_reverse_charge_applicable;
								$service_invoice->po_reference_number = $po_reference_number;
								$service_invoice->invoice_number = $reference_invoice_number;
								$service_invoice->invoice_date = $reference_invoice_date;
								$service_invoice->to_account_type_id = $to_account_type_id;
								$service_invoice->customer_id = $customer->id;
								$service_invoice->address_id = $to_account_type_id == 1440 ? ($customer_address ? $customer_address->id : NULL) : ($vendor_address ? $vendor_address->id : NULL);
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
								$service_invoice_item->e_invoice_uom_id = $uom->id;
								$service_invoice_item->description = $item_record['Reference'];
								// $service_invoice_item->qty = 1;
								// $service_invoice_item->sub_total = 1 * $record['Amount'];
								$service_invoice_item->qty = $item_record['Quantity'];
								$service_invoice_item->rate = $item_record['Amount'];
								$service_invoice_item->sub_total = $item_record['Quantity'] * $item_record['Amount'];
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
								$amount_total += $item_record['Amount'];
								$service_invoice->amount_total = $amount_total;
								$service_invoice->tax_total = $item_code->sac_code_id ? $total_tax_amount : 0;
								$sub_total += $item_record['Quantity'] * $item_record['Amount'];
								$service_invoice->sub_total = $sub_total;
								// $service_invoice->sub_total = 1 * $item_record['Amount'];
								$total += $item_record['Quantity'] * $item_record['Amount'] + $total_tax_amount;
								$service_invoice->total = $total;

								$invoice_amount += $item_record['Quantity'] * $item_record['Amount'] + $total_tax_amount;
								// dump($invoice_amount, $round_off_invoice_amount);
								//FOR ROUND OFF
								if ($invoice_amount <= round($invoice_amount)) {
									$round_off = round($invoice_amount) - $invoice_amount;
								} else {
									$round_off = $invoice_amount - round($invoice_amount);
								}
								$service_invoice->round_off_amount = number_format($round_off, 2);
								$service_invoice->final_amount = round($invoice_amount);
								$service_invoice->save();

								DB::commit();
								//UPDATING PROGRESS FOR EVERY FIVE RECORDS
								if (($k + 1) % 5 == 0) {
									$job->save();
								}
							}
						}
					}
					$job->incrementNew();
					$i++;
					$objPHPExcel = PHPExcel_IOFactory::load(storage_path('app/' . $job->src_file));
					$sheet = $objPHPExcel->getSheet(0);
					$highestRow = $sheet->getHighestDataRow();

					$header = $sheet->rangeToArray('A1:N1', NULL, TRUE, FALSE);
					$header = $header[0];
					$rows = $sheet->rangeToArray('A' . $i . ':N' . $i . $highestRow, NULL, TRUE, FALSE);
					// dump('-------------------------------------------------------');
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
		if ($this->number == 'F21MDSDN0001') {
			dump('static outlet');
			$this['branch_id'] = 134; //TRY - Trichy
			$this->outlets = $this->outlets ? $this->outlets : 'NA';
		} else {
			$this->outlets = $this->outlets ? $this->outlets : 'NA';
		}

		$this->customer->formatted_address = $this->customer->primaryAddress ? $this->customer->primaryAddress->address_line1 : 'NA';
		// $city = City::where('name', $this->customer->city)->first();
		// // dd($city);
		// $state = State::find($city->state_id);

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
			$this->sac_code_status = 'Tax Invoice(DBN)';
			$this->document_type = 'DBN';
		} else {
			$this->sac_code_status = 'Invoice(INV)';
			$this->document_type = 'INV';
		}

		if ($this->total > $this->final_amount) {
			$this->round_off_amount = number_format(($this->final_amount - $this->total), 2);
		} elseif ($this->total < $this->final_amount) {
			$this->round_off_amount;
		} else {
			$this->round_off_amount = 0;
		}
		if ($this->to_account_type_id == 1440 || $this->to_account_type_id == 1440) {
			$city = City::where('name', $this->address->city)->first();
			// dd($city);
			$state = State::find($this->address->state_id);
			$this->address->state_code = $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-';
		} else {
			$state = State::find($this->customer->primaryAddress ? $this->customer->primaryAddress->state_id : NULL);
			$this->customer->state_code = $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-';
			$address = Address::with(['city', 'state', 'country'])->where('address_of_id', 21)->where('entity_id', $this->customer_id)->first();
			if ($address) {
				$this->customer->address .= $address->address_line1 ? $address->address_line1 . ', ' : '';
				$this->customer->address .= $address->address_line2 ? $address->address_line2 . ', ' : '';
				$this->customer->address .= $address->city ? $address->city->name . ', ' : '';
				$this->customer->address .= $address->state ? $address->state->name . ', ' : '';
				$this->customer->address .= $address->country ? $address->country->name . ', ' : '';
				$this->customer->address .= $address->pincode ? $address->pincode . '.' : '';
			} else {
				$this->customer->address = '';
			}
		}

		// $this->customer->state_code = $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-';

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

		$pdf = app('dompdf.wrapper');
		$pdf->getDomPDF()->set_option("enable_php", true);
		$pdf = $pdf->loadView('service-invoices/pdf/index', $data);
		// $po_file_name = 'Invoice-' . $this->number . '.pdf';
		File::delete($pathToFile);
		File::put($pathToFile, $pdf->output());
	}

	public static function percentage($num, $per) {
		return ($num / 100) * $per;
	}

	public static function apiLogs($params) {
		// dd($params);
		$api_log = new ApiLog;
		$api_log->type_id = $params['type_id'];
		$api_log->entity_number = $params['entity_number'];
		$api_log->entity_id = $params['entity_id'];
		$api_log->url = $params['url'];
		$api_log->src_data = $params['src_data'];
		$api_log->response_data = $params['response_data'];
		$api_log->user_id = $params['user_id'];
		$api_log->status_id = $params['status_id'];
		$api_log->errors = $params['errors'];
		$api_log->created_by_id = $params['created_by_id'];
		$api_log->save();

		return $api_log;
	}
}
