<?php
namespace Abs\ServiceInvoicePkg;
use Abs\ApprovalPkg\ApprovalLevel;
use Abs\ApprovalPkg\ApprovalTypeStatus;
use Abs\AttributePkg\Field;
use Abs\AttributePkg\FieldConfigSource;
use Abs\AttributePkg\FieldGroup;
use Abs\AttributePkg\FieldSourceTable;
use Abs\AxaptaExportPkg\AxaptaExport;
use Abs\SerialNumberPkg\SerialNumberGroup;
use Abs\ServiceInvoicePkg\ServiceInvoice;
use Abs\ServiceInvoicePkg\ServiceInvoiceItem;
use Abs\ServiceInvoicePkg\ServiceItem;
use Abs\ServiceInvoicePkg\ServiceItemCategory;
use Abs\TaxPkg\Tax;
use App\Address;
use App\ApiLog;
use App\Attachment;
use App\City;
use App\Company;
use App\Config;
use App\Customer;
use App\EInvoiceUom;
use App\Employee;
use App\Entity;
use App\FinancialYear;
use App\Http\Controllers\Controller;
use App\Outlet;
use App\Sbu;
use App\State;
use App\User;
use App\Vendor;
use Artisaninweb\SoapWrapper\SoapWrapper;
use Auth;
use Carbon\Carbon;
use DB;
use Entrust;
use Excel;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use phpseclib\Crypt\RSA as Crypt_RSA;
use QRCode;
use Session;
use URL;
use Validator;
use Yajra\Datatables\Datatables;

class ServiceInvoiceController extends Controller {

	public function __construct() {
		$this->middleware('auth');
		$this->soapWrapper = new SoapWrapper;
	}

	public function getServiceInvoiceFilter() {
		$this->data['extras'] = [
			'sbu_list' => [],
			'category_list' => collect(ServiceItemCategory::select('id', 'name')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
			'sub_category_list' => [],
			'cn_dn_statuses' => collect(ApprovalTypeStatus::select('id', 'status')->where('approval_type_id', 1)->orderBy('id', 'asc')->get())->prepend(['id' => '', 'status' => 'Select CN/DN Status']),
			'type_list' => collect(Config::select('id', 'name')->where('config_type_id', 84)->get())->prepend(['id' => '', 'name' => 'Select Service Invoice Type']),
		];
		return response()->json($this->data);
	}

	public function getServiceInvoiceList(Request $request) {
		//dd($request->all());
		if (!empty($request->invoice_date)) {
			$document_date = explode('to', $request->invoice_date);
			$first_date_this_month = date('Y-m-d', strtotime($document_date[0]));
			$last_date_this_month = date('Y-m-d', strtotime($document_date[1]));
		} else {
			$first_date_this_month = '';
			$last_date_this_month = '';
		}
		$invoice_number_filter = $request->invoice_number;
		$service_invoice_list = ServiceInvoice::withTrashed()
			->select(
				'service_invoices.id',
				'service_invoices.number',
				'service_invoices.document_date',
				'service_invoices.total as invoice_amount',
				'service_invoices.is_cn_created',
				'service_invoices.status_id',
				'service_invoices.ack_date',
				'outlets.code as branch',
				'sbus.name as sbu',
				'service_item_categories.name as category',
				'service_item_sub_categories.name as sub_category',
				// DB::raw('IF(service_invoices.to_account_type_id=1440,customers.code,vendors.code) as customer_code'),
				// DB::raw('IF(service_invoices.to_account_type_id=1440,customers.name,vendors.name) as customer_name'),
				DB::raw('CASE
                    WHEN service_invoices.to_account_type_id = "1440" THEN customers.code
                    WHEN service_invoices.to_account_type_id = "1441" THEN vendors.code
                    ELSE customers.code END AS customer_code'),
				DB::raw('CASE
                    WHEN service_invoices.to_account_type_id = "1440" THEN customers.name
                    WHEN service_invoices.to_account_type_id = "1441" THEN vendors.name
                    ELSE customers.name END AS customer_name'),
				// 'customers.code as customer_code',
				// 'customers.name as customer_name',
				'configs.name as type_name',
				'configs.id as si_type_id',
				DB::raw('IF(to_account_type.name IS NULL,"Customer",to_account_type.name) as to_account_type'),
				'approval_type_statuses.status',
				'service_invoices.created_by_id'
			)
			->join('outlets', 'outlets.id', 'service_invoices.branch_id')
			->join('sbus', 'sbus.id', 'service_invoices.sbu_id')
			->leftJoin('service_item_sub_categories', 'service_item_sub_categories.id', 'service_invoices.sub_category_id')
			->leftJoin('service_item_categories', 'service_item_categories.id', 'service_invoices.category_id')
			->leftJoin('customers', function ($join) {
				$join->on('customers.id', 'service_invoices.customer_id');
			})
			->leftJoin('vendors', function ($join) {
				$join->on('vendors.id', 'service_invoices.customer_id');
			})
			->join('configs', 'configs.id', 'service_invoices.type_id')
			->leftJoin('configs as to_account_type', 'to_account_type.id', 'service_invoices.to_account_type_id')
			->join('approval_type_statuses', 'approval_type_statuses.id', 'service_invoices.status_id')
		// ->where('service_invoices.company_id', Auth::user()->company_id)
			->whereIn('approval_type_statuses.approval_type_id', [1, 3, 5])
			->where(function ($query) use ($first_date_this_month, $last_date_this_month) {
				if (!empty($first_date_this_month) && !empty($last_date_this_month)) {
					$query->whereRaw("DATE(service_invoices.document_date) BETWEEN '" . $first_date_this_month . "' AND '" . $last_date_this_month . "'");
				}
			})
			->where(function ($query) use ($invoice_number_filter) {
				if ($invoice_number_filter != null) {
					$query->where('service_invoices.number', 'like', "%" . $invoice_number_filter . "%");
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->type_id)) {
					$query->where('service_invoices.type_id', $request->type_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->branch_id)) {
					$query->where('service_invoices.branch_id', $request->branch_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->sbu_id)) {
					$query->where('service_invoices.sbu_id', $request->sbu_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->category_id)) {
					// $query->where('service_item_sub_categories.category_id', $request->category_id);
					$query->where('service_invoices.category_id', $request->category_id);
				}
			})
		// ->where(function ($query) use ($request) {
		// 	if (!empty($request->sub_category_id)) {
		// 		$query->where('service_invoices.sub_category_id', $request->sub_category_id);
		// 	}
		// })
			->where(function ($query) use ($request) {
				if (!empty($request->customer_id)) {
					$query->where('service_invoices.customer_id', $request->customer_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->status_id)) {
					$query->where('service_invoices.status_id', $request->status_id);
				}
			})
			->groupBy('service_invoices.id')
			->orderBy('service_invoices.id', 'Desc');
		// ->get();
		// dd($service_invoice_list);
		if (Entrust::can('view-all-cn-dn')) {
			$service_invoice_list = $service_invoice_list->where('service_invoices.company_id', Auth::user()->company_id);
		} elseif (Entrust::can('view-own-cn-dn')) {
			$service_invoice_list = $service_invoice_list->where('service_invoices.created_by_id', Auth::user()->id);
		} elseif (Entrust::can('view-outlet-based-cn-dn')) {
			$view_user_outlets_only = User::leftJoin('employees', 'employees.id', 'users.entity_id')
				->leftJoin('employee_outlet', 'employee_outlet.employee_id', 'employees.id')
				->leftJoin('outlets', 'outlets.id', 'employee_outlet.outlet_id')
				->where('employee_outlet.employee_id', Auth::user()->entity_id)
				->where('users.company_id', Auth::user()->company_id)
				->where('users.user_type_id', 1)
				->pluck('employee_outlet.outlet_id')
				->toArray();
			$service_invoice_list = $service_invoice_list->whereIn('service_invoices.branch_id', $view_user_outlets_only);
		} elseif (Entrust::can('view-sbu-based-cn-dn')) {
			$view_employee_sbu_only = Employee::leftJoin('users', 'users.entity_id', 'employees.id')
				->leftJoin('employee_sbu', 'employee_sbu.employee_id', 'employees.id')
				->where('employee_sbu.employee_id', Auth::user()->entity_id)
				->where('employees.company_id', Auth::user()->company_id)
				->where('users.user_type_id', 1)
				->pluck('employee_sbu.sbu_id')
				->toArray();
			$service_invoice_list = $service_invoice_list->whereIn('service_invoices.sbu_id', $view_employee_sbu_only);
		} else {
			$service_invoice_list = [];
		}
		return Datatables::of($service_invoice_list)
			->addColumn('child_checkbox', function ($service_invoice_list) {
				$checkbox = "<td><div class='table-checkbox'><input type='checkbox' id='child_" . $service_invoice_list->id . "' name='child_boxes' value='" . $service_invoice_list->id . "' class='service_invoice_checkbox'/><label for='child_" . $service_invoice_list->id . "'></label></div></td>";

				return $checkbox;
			})
			->addColumn('invoice_amount', function ($service_invoice_list) {
				if ($service_invoice_list->type_name == 'CN') {
					return '-' . $service_invoice_list->invoice_amount;
				} else {
					return $service_invoice_list->invoice_amount;
				}

			})
			->addColumn('action', function ($service_invoice_list) {
				// $type_id = $service_invoice_list->si_type_id == '1060' ? 1060 : 1061;
				$type_id = $service_invoice_list->si_type_id;
				$img_edit = asset('public/theme/img/table/cndn/edit.svg');
				$img_view = asset('public/theme/img/table/cndn/view.svg');
				$img_download = asset('public/theme/img/table/cndn/download.svg');
				$img_delete = asset('public/theme/img/table/cndn/delete.svg');
				$img_approval = asset('public/theme/img/table/cndn/approval.svg');
				$path = URL::to('/storage/app/public/service-invoice-pdf');
				$output = '';
				if ($service_invoice_list->status_id == '4') {
					$output .= '<a href="#!/service-invoice-pkg/service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>
	                    	<a href="' . $path . '/' . $service_invoice_list->number . '.pdf" class="" target="_blank"><img class="img-responsive" src="' . $img_download . '" alt="Download" />
	                        </a>';
					if ($service_invoice_list->ack_date) {
						$current_date_time = date('d-m-Y H:i:s');
						if (!empty($service_invoice_list->ack_date)) {
							$t1 = strtotime($service_invoice_list->ack_date);
							$t2 = strtotime($current_date_time);
							$diff = $t2 - $t1;
							$hours = $diff / (60 * 60);
							if ($hours < 24) {
								$output .= '<a href="javascript:;" data-toggle="modal" data-target="#delete_irn"
									onclick="angular.element(this).scope().deleteIRN(' . $service_invoice_list->id . ')" dusk = "delete-btn" title="Cancel IRN">
									<img src="' . $img_delete . '" alt="Cancel IRN" class="img-responsive">
									</a>';
							} else {
								$output .= '';
							}
						} else {
							$output .= '';
						}

					}
				} elseif ($service_invoice_list->status_id != '4' && $service_invoice_list->status_id != '3' && $service_invoice_list->status_id != '7') {
					$output .= '<a href="#!/service-invoice-pkg/service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>
	                    	<a href="#!/service-invoice-pkg/service-invoice/edit/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_edit . '" alt="Edit" />
	                    	</a>';
				} elseif ($service_invoice_list->status_id == '3') {
					$output .= '<a href="#!/service-invoice-pkg/service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>';
				} elseif ($service_invoice_list->status_id == '7') {
					$output .= '<a href="#!/service-invoice-pkg/service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>
	                    	<a href="' . $path . '/' . $service_invoice_list->number . '.pdf" class="" target="_blank"><img class="img-responsive" src="' . $img_download . '" alt="Download" />
	                        </a>';
					if ($service_invoice_list->ack_date) {
						$current_date_time = date('d-m-Y H:i:s');
						if (!empty($service_invoice_list->ack_date)) {
							$t1 = strtotime($service_invoice_list->ack_date);
							$t2 = strtotime($current_date_time);
							$diff = $t2 - $t1;
							$hours = $diff / (60 * 60);
							if ($hours < 24 && $service_invoice_list->state_id == 7) {
								$output .= '<a href="javascript:;" data-toggle="modal" data-target="#delete_irn"
									onclick="angular.element(this).scope().deleteIRN(' . $service_invoice_list->id . ')" dusk = "delete-btn" title="Cancel IRN">
									<img src="' . $img_delete . '" alt="Cancel IRN" class="img-responsive">
									</a>';
							} else {
								$output .= '';
							}
						} else {
							$output .= '';
						}
					}

				}
				if ($service_invoice_list->status_id == '1') {
					$next_status = 2; //ApprovalLevel::where('approval_type_id', 1)->pluck('current_status_id')->first();
					$output .= '<a href="javascript:;" data-toggle="modal" data-target="#send-to-approval"
					onclick="angular.element(this).scope().sendApproval(' . $service_invoice_list->id . ',' . $next_status . ')" title="Send for Approval">
					<img src="' . $img_approval . '" alt="Send for Approval" class="img-responsive">
					</a>';
				}
				return $output;
			})
			->rawColumns(['child_checkbox', 'action'])
			->make(true);
	}

	public function getFormData($type_id = NULL, $id = NULL) {
		if (!$id) {
			$service_invoice = new ServiceInvoice;
			// $service_invoice->invoice_date = date('d-m-Y');
			$this->data['action'] = 'Add';
			Session::put('sac_code_value', 'new');
		} else {
			$service_invoice = ServiceInvoice::with([
				'attachments',
				'toAccountType',
				'address',
				// 'customer',
				// 'customer.primaryAddress',
				'branch',
				'branch.primaryAddress',
				'serviceInvoiceItems',
				'serviceInvoiceItems.eInvoiceUom',
				'serviceInvoiceItems.serviceItem',
				'serviceInvoiceItems.eavVarchars',
				'serviceInvoiceItems.eavInts',
				'serviceInvoiceItems.eavDatetimes',
				'serviceInvoiceItems.taxes',
				'serviceItemSubCategory',
				'serviceItemCategory',
			])->find($id);
			if (!$service_invoice) {
				return response()->json(['success' => false, 'error' => 'Service Invoice not found']);
			}
			$service_invoice->customer; //ADDED FOR CUSTOMER AND VENDOR BOTH
			// $service_invoice->customer->primaryAddress; //ADDED FOR CUSTOMER AND VENDOR BOTH
			// $service_invoice->customer_address = $service_invoice->address; //ADDED FOR CUSTOMER AND VENDOR BOTH
			$fields = Field::withTrashed()->get()->keyBy('id');
			if (count($service_invoice->serviceInvoiceItems) > 0) {
				$gst_total = 0;
				foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
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
								->select('field_id as id', 'value')
								->get()
								->toArray();
							$fd_datetimes = DB::table('eav_datetime')
								->where('entity_type_id', 1040)
								->where('entity_id', $serviceInvoiceItem->id)
								->where('field_group_id', $fg_id)
								->select('field_id as id', 'value')
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
								->select(
									'field_id as id',
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

					//TAX CALC
					if (count($serviceInvoiceItem->taxes) > 0) {
						$gst_total = 0;
						foreach ($serviceInvoiceItem->taxes as $key => $value) {
							$gst_total += round($value->pivot->amount, 2);
							$serviceInvoiceItem[$value->name] = [
								'amount' => round($value->pivot->amount, 2),
								'percentage' => round($value->pivot->percentage, 2),
							];
						}
					}
					$serviceInvoiceItem->total = round($serviceInvoiceItem->sub_total, 2) + round($gst_total, 2);
					$serviceInvoiceItem->code = $serviceInvoiceItem->serviceItem->code;
					$serviceInvoiceItem->name = $serviceInvoiceItem->serviceItem->name;
					$serviceInvoiceItem->sac_code_value = $serviceInvoiceItem->serviceItem->sac_code_id;
					session(['sac_code_value' => $serviceInvoiceItem->sac_code_value]);
					//dd($serviceInvoiceItem->sac_code_value);
				}
			}

			$this->data['action'] = 'Edit';
		}

		$this->data['extras'] = [
			// 'branch_list' => collect(Outlet::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Branch']),
			// 'sbu_list' => collect(Sbu::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Sbu']),
			'sbu_list' => [],
			'tax_list' => Tax::select('name', 'id')->where('company_id', Auth::user()->company_id)->get(),
			'category_list' => collect(ServiceItemCategory::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
			'sub_category_list' => [],
			'uom_list' => EInvoiceUom::getList(),
			'to_account_type_list' => Config::select('name', 'id')->where('config_type_id', 27)->whereIn('id', [1440, 1441])->get(), //ACCOUNT TYPES
			'addresses' => Address::where([
				'entity_id' => $service_invoice->customer_id,
				'company_id' => Auth::user()->company_id,
				'address_of_id' => 24,
			])->get(), //ACCOUNT TYPES
			// 'sub_category_list' => [],
		];
		$this->data['config_values'] = Entity::where('company_id', Auth::user()->company_id)->whereIn('entity_type_id', [15, 16])->get();
		$this->data['service_invoice'] = $service_invoice;
		$this->data['success'] = true;
		return response()->json($this->data);
	}

	// public function getServiceItemSubCategories($service_item_category_id) {
	// 	return ServiceItemSubCategory::getServiceItemSubCategories($service_item_category_id);
	// }

	public function getSbus($outlet_id) {
		return Sbu::getSbus($outlet_id);
	}

	// public function searchCustomer(Request $r) {
	// 	return Customer::searchCustomer($r);
	// }

	public function searchField(Request $r) {
		return Field::searchField($r);
	}

	// public function getCustomerDetails(Request $request) {
	// 	return Customer::getDetails($request);
	// }

	public function searchBranch(Request $r) {
		return Outlet::search($r);
	}

	public function getBranchDetails(Request $request) {
		return Outlet::getDetails($request);
	}

	public function searchServiceItem(Request $r) {
		return ServiceItem::searchServiceItem($r);
	}

	public function getServiceItemDetails(Request $request) {
		// dd($request->all());

		//GET TAXES BY CONDITIONS
		$taxes = Tax::getTaxes($request->service_item_id, $request->branch_id, $request->customer_id, $request->to_account_type_id, $request->state_id);
		// dd($taxes);
		if (!$taxes['success']) {
			return response()->json(['success' => false, 'error' => $taxes['error']]);
		}

		if ($request->btn_action == 'add') {
			$service_item = ServiceItem::with([
				'fieldGroups',
				'fieldGroups.fields',
				'fieldGroups.fields.fieldType',
				'coaCode',
				'taxCode',
				'taxCode.taxes' => function ($query) use ($taxes) {
					$query->whereIn('tax_id', $taxes['tax_ids']);
				},
			])
				->find($request->service_item_id);
			if (!$service_item) {
				return response()->json(['success' => false, 'error' => 'Service Item not found']);
			}

			if (count($service_item->fieldGroups) > 0) {
				foreach ($service_item->fieldGroups as $key => $fieldGroup) {
					if (count($fieldGroup->fields) > 0) {
						foreach ($fieldGroup->fields as $key => $field) {
							//SINGLE SELECT DROPDOWN | MULTISELECT DROPDOWN
							if ($field->type_id == 1 || $field->type_id == 2) {
								// LIST SOURCE - TABLE
								if ($field->list_source_id == 1180) {
									$source_table = FieldSourceTable::withTrashed()->find($field->source_table_id);
									if (!$source_table) {
										$field->get_list = [];
									} else {
										$nameSpace = '\\App\\';
										$entity = $source_table->model;
										$model = $nameSpace . $entity;
										$placeholder = 'Select ' . $entity;
										//OTHER THAN MULTISELECT
										if ($field->type_id != 2) {
											$field->get_list = collect($model::select('name', 'id')->get())->prepend(['id' => '', 'name' => $placeholder]);
										} else {
											$field->get_list = $model::select('name', 'id')->get();
										}
									}
								} elseif ($field->list_source_id == 1181) {
									// LIST SOURCE - CONFIG
									$source_table = FieldConfigSource::withTrashed()->find($field->source_table_id);
									if (!$source_table) {
										$field->get_list = [];
									} else {
										$nameSpace = '\\App\\';
										$entity = $source_table->name;
										$model = $nameSpace . 'Config';
										$placeholder = 'Select ' . $entity;
										//OTHER THAN MULTISELECT
										if ($field->type_id != 2) {
											$field->get_list = collect($model::select('name', 'id')->where('config_type_id', $source_table->id)->get())->prepend(['id' => '', 'name' => $placeholder]);
										} else {
											$field->get_list = $model::select('name', 'id')->where('config_type_id', $source_table->id)->get();
										}
									}
								} else {
									$field->get_list = [];
								}
							} elseif ($field->type_id == 9) {
								//SWITCH
								$field->value = 'Yes';
							}
						}
					}
				}
			}
		} else {
			$service_item = ServiceItem::with([
				'coaCode',
				'taxCode',
				'taxCode.taxes' => function ($query) use ($taxes) {
					$query->whereIn('tax_id', $taxes['tax_ids']);
				},
			])
				->find($request->service_item_id);
			// dd($service_item);
			if (!$service_item) {
				return response()->json(['success' => false, 'error' => 'Service Item not found']);
			}
			if ($request->field_groups) {
				if (count($request->field_groups) > 0) {
					//FIELDGROUPS
					$fd_gps_val = [];
					foreach ($request->field_groups as $fg_key => $fg) {
						//GET FIELD GROUP VALUE
						$fg_val = FieldGroup::withTrashed()->find($fg['id']);
						if (!$fg_val) {
							return response()->json(['success' => false, 'error' => 'FieldGroup not found']);
						}

						//PUSH FIELD GROUP TO NEW ARRAY
						$fg_v = [];
						$fg_v = [
							'id' => $fg_val->id,
							'name' => $fg_val->name,
						];

						//FIELDS
						if (count($fg['fields']) > 0) {
							foreach ($fg['fields'] as $fd_key => $fd) {
								$field = Field::find($fd['id']);
								//PUSH FIELDS TO FIELD GROUP CREATED ARRAY
								$fg_v['fields'][$fd_key] = Field::withTrashed()->find($fd['id']);
								if (!$fg_v['fields'][$fd_key]) {
									return response()->json(['success' => false, 'error' => 'Field not found']);
								}
								//SINGLE SELECT DROPDOWN | MULTISELECT DROPDOWN
								if ($field->type_id == 1 || $field->type_id == 2) {
									// LIST SOURCE - TABLE
									if ($field->list_source_id == 1180) {
										$source_table = FieldSourceTable::withTrashed()->find($field->source_table_id);
										if (!$source_table) {
											$fg_v['fields'][$fd_key]->get_list = [];
											$fg_v['fields'][$fd_key]->value = is_string($fd['value']) ? json_decode($fd['value']) : $fd['value'];
										} else {
											$nameSpace = '\\App\\';
											$entity = $source_table->model;
											$model = $nameSpace . $entity;
											$placeholder = 'Select ' . $entity;
											//OTHER THAN MULTISELECT
											if ($field->type_id != 2) {
												$fg_v['fields'][$fd_key]->get_list = collect($model::select('name', 'id')->get())->prepend(['id' => '', 'name' => $placeholder]);
											} else {
												$fg_v['fields'][$fd_key]->get_list = $model::select('name', 'id')->get();
											}
											$fg_v['fields'][$fd_key]->value = is_string($fd['value']) ? json_decode($fd['value']) : $fd['value'];
										}
									} elseif ($field->list_source_id == 1181) {
										// LIST SOURCE - CONFIG
										$source_table = FieldConfigSource::withTrashed()->find($field->source_table_id);
										if (!$source_table) {
											$fg_v['fields'][$fd_key]->get_list = [];
											$fg_v['fields'][$fd_key]->value = is_string($fd['value']) ? json_decode($fd['value']) : $fd['value'];
										} else {
											$nameSpace = '\\App\\';
											$entity = $source_table->name;
											$model = $nameSpace . 'Config';
											$placeholder = 'Select ' . $entity;
											//OTHER THAN MULTISELECT
											if ($field->type_id != 2) {
												$fg_v['fields'][$fd_key]->get_list = collect($model::select('name', 'id')->where('config_type_id', $source_table->id)->get())->prepend(['id' => '', 'name' => $placeholder]);
											} else {
												$fg_v['fields'][$fd_key]->get_list = $model::select('name', 'id')->where('config_type_id', $source_table->id)->get();
											}
											$fg_v['fields'][$fd_key]->value = is_string($fd['value']) ? json_decode($fd['value']) : $fd['value'];
										}
									} else {
										$fg_v['fields'][$fd_key]->get_list = [];
										$fg_v['fields'][$fd_key]->value = is_string($fd['value']) ? json_decode($fd['value']) : $fd['value'];
									}
								} elseif ($field->type_id == 7 || $field->type_id == 8 || $field->type_id == 3 || $field->type_id == 4 || $field->type_id == 9) {
									//DATE PICKER | DATETIME PICKER | FREE TEXT BOX | NUMERIC TEXT BOX | SWITCH
									$fg_v['fields'][$fd_key]->value = $fd['value'];
								} elseif ($field->type_id == 10) {
									//AUTOCOMPLETE
									// LIST SOURCE - TABLE
									if ($field->list_source_id == 1180) {
										$source_table = FieldSourceTable::withTrashed()->find($field->source_table_id);
										if (!$source_table) {
											$fg_v['fields'][$fd_key]->autoval = [];
										} else {
											$nameSpace = '\\App\\';
											$entity = $source_table->model;
											$model = $nameSpace . $entity;
											$fg_v['fields'][$fd_key]->autoval = $model::where('id', $fd['value'])
												->select(
													'id',
													'name',
													'code'
												)
												->first();
										}
									} elseif ($field->list_source_id == 1181) {
										// LIST SOURCE - CONFIG
										$source_table = FieldConfigSource::withTrashed()->find($field->source_table_id);
										if (!$source_table) {
											$fg_v['fields'][$fd_key]->autoval = [];
										} else {
											$nameSpace = '\\App\\';
											$entity = $source_table->name;
											$model = $nameSpace . 'Config';
											$fg_v['fields'][$fd_key]->autoval = $model::where('id', $fd['value'])
												->select(
													'id',
													'name',
													'code'
												)
												->where('config_type_id', $source_table->id)
												->first();
										}
									} else {
										$fg_v['fields'][$fd_key]->autoval = [];
									}
								}
								//FOR FIELD IS REQUIRED OR NOT
								$is_required = DB::table('field_group_field')->where('field_group_id', $fg['id'])->where('field_id', $fd['id'])->first();
								$fg_v['fields'][$fd_key]->pivot = [];
								if ($is_required) {
									$fg_v['fields'][$fd_key]->pivot = [
										'is_required' => $is_required->is_required,
									];
								} else {
									$fg_v['fields'][$fd_key]->pivot = [
										'is_required' => 0,
									];
								}
							}
						}
						//PUSH INDIVUDUAL FIELD GROUP TO MAIN ARRAY
						$fd_gps_val[] = $fg_v;
					}
					//PUSH MAIN FIELD GROUPS TO VARIABLE
					$service_item->field_groups = $fd_gps_val;
				}
			}
		}
		$sac_code_value = $service_item->sac_code_id;
		//dd($sac_code_value);
		session(['sac_code_value' => $sac_code_value]);
		// dd($service_item);
		return response()->json(['success' => true, 'service_item' => $service_item]);
	}

	public function getServiceItem(Request $request) {
		// dd($request->all());
		//GET TAXES BY CONDITIONS
		$taxes = Tax::getTaxes($request->service_item_id, $request->branch_id, $request->customer_id, $request->to_account_type_id, $request->state_id);
		if (!$taxes['success']) {
			return response()->json(['success' => false, 'error' => $taxes['error']]);
		}

		$outlet = Outlet::find($request->branch_id);
		if ($request->to_account_type_id == 1440) {
			$customer = Customer::with(['primaryAddress'])->find($request->customer_id);
		} else {
			$customer = Vendor::with(['primaryAddress'])->find($request->customer_id);
		}

		$service_item = ServiceItem::with([
			'coaCode',
			'taxCode',
			'taxCode.taxes' => function ($query) use ($taxes) {
				$query->whereIn('tax_id', $taxes['tax_ids']);
			},
		])
			->find($request->service_item_id);
		if (!$service_item) {
			return response()->json(['success' => false, 'error' => 'Service Item not found']);
		}

		//TAX CALC AND PUSH
		$gst_total = 0;
		if (!is_null($service_item->sac_code_id)) {
			if (count($service_item->taxCode->taxes) > 0) {
				foreach ($service_item->taxCode->taxes as $key => $value) {
					$gst_total += round(($value->pivot->percentage / 100) * ($request->qty * $request->amount), 2);
					$service_item[$value->name] = [
						'amount' => round(($value->pivot->percentage / 100) * ($request->qty * $request->amount), 2),
						'percentage' => round($value->pivot->percentage, 2),
					];
				}
			}
		}
		// else {
		// 	if ($customer->primaryAddress->state_id) {
		// 		if (($customer->primaryAddress->state_id == 3) && ($outlet->state_id == 3)) {
		// 			//3 FOR KERALA
		// 			//check customer state and outlet states are equal KL.  //add KFC tax
		// 			$gst_total += round((1 / 100) * ($request->qty * $request->amount), 2);
		// 			// $KFC_tax_amount = round($service_invoice_item->sub_total * 1 / 100, 2); //ONE PERCENTAGE FOR KFC
		// 			$service_item['KFC'] = [
		// 				'amount' => round((1 / 100) * ($request->qty * $request->amount), 2),
		// 				'percentage' => round(1, 2),
		// 			];
		// 		}
		// }
		// }
		$KFC_tax_amount = 0;
		// if ($customer->primaryAddress->state_id) {
		// 	if (($customer->primaryAddress->state_id == 3) && ($outlet->state_id == 3)) {
		// 		//3 FOR KERALA
		// 		//check customer state and outlet states are equal KL.  //add KFC tax
		// 		if (!$customer->gst_number) {
		// 			dd(1);
		// 			//customer dont't have GST
		// 			if (!is_null($service_item->sac_code_id)) {
		// 				//customer have HSN and SAC Code
		// 				$gst_total += round((1 / 100) * ($request->qty * $request->amount), 2);
		// 				$KFC_tax_amount = round($request->qty * $request->amount * 1 / 100, 2); //ONE PERCENTAGE FOR KFC
		// 				$service_item['KFC'] = [ //4 for KFC
		// 					'percentage' => 1,
		// 					'amount' => $KFC_tax_amount,
		// 				];
		// 			}
		// 		}
		// 	}
		// }
		if ($request->state_id) {
			// dd('in');
			if (($request->state_id == 3) && ($outlet->state_id == 3)) {
				//3 FOR KERALA
				//check customer state and outlet states are equal KL.  //add KFC tax
				if (!$request->gst_number) {
					//customer dont't have GST
					if (!is_null($service_item->sac_code_id)) {
						//customer have HSN and SAC Code
						$gst_total += round((1 / 100) * ($request->qty * $request->amount), 2);
						$KFC_tax_amount = round($request->qty * $request->amount * 1 / 100, 2); //ONE PERCENTAGE FOR KFC
						$service_item['KFC'] = [ //4 for KFC
							'percentage' => 1,
							'amount' => $KFC_tax_amount,
						];
					}
				}
			}
		}
		// dump($gst_total);
		//FOR TCS TAX CALCULATION
		$TCS_tax_amount = 0;
		$tcs_total = 0;
		if ($service_item) {
			if ($service_item->tcs_percentage) {
				// $gst_total += round(($service_item->tcs_percentage / 100) * ($request->qty * $request->amount), 2);
				$tcs_total = round(($gst_total + $request->qty * $request->amount) * $service_item->tcs_percentage / 100, 2);
				// dd($tcs_total);
				$TCS_tax_amount = round(($gst_total + $request->qty * $request->amount) * $service_item->tcs_percentage / 100, 2); //ONE PERCENTAGE FOR TCS
				$service_item['TCS'] = [ // for TCS
					'percentage' => $service_item->tcs_percentage,
					'amount' => $TCS_tax_amount,
				];
			}
		}
		// dd(1);
		//FIELD GROUPS PUSH
		if (isset($request->field_groups)) {
			if (!empty($request->field_groups)) {
				$service_item->field_groups = $request->field_groups;
			}
		}

		//GET E-INVOICE UOM
		$e_invoice_uom = EInvoiceUom::find($request->e_invoice_uom_id);

		$service_item->service_item_id = $service_item->id;
		$service_item->id = null;
		$service_item->description = $request->description;
		$service_item->qty = $request->qty;
		$service_item->e_invoice_uom = $e_invoice_uom;
		$service_item->rate = $request->amount;
		$service_item->sub_total = round(($request->qty * $request->amount), 2);
		$service_item->total = round($request->qty * $request->amount, 2) + $gst_total + $tcs_total;

		if ($request->action == 'add') {
			$add = true;
			$message = 'Service item added successfully';
		} else {
			$add = false;
			$message = 'Service item updated successfully';
		}
		$add = ($request->action == 'add') ? true : false;
		return response()->json(['success' => true, 'service_item' => $service_item, 'add' => $add, 'message' => $message]);

	}

	public function saveServiceInvoice(Request $request) {
		// dd($request->all());
		DB::beginTransaction();
		try {

			$error_messages = [
				'branch_id.required' => 'Branch is required',
				'sbu_id.required' => 'Sbu is required',
				'category_id.required' => 'Category is required',
				// 'sub_category_id.required' => 'Sub Category is required',
				// 'invoice_date.required_if' => 'Invoice date is required',
				'document_date.required' => 'Document date is required',
				'customer_id.required' => 'Customer is required',
				'proposal_attachments.*.required' => 'Please upload an image',
				'proposal_attachments.*.mimes' => 'Only jpeg,png and bmp images are allowed',
				'number.unique' => 'Service invoice number has already been taken',
				// 'is_reverse_charge_applicable.required' => 'Reverse Charge Applicale is required',
				// 'po_reference_number.required' => 'PO Reference Number is required',
				// 'invoice_number.required_if' => 'Invoice Number is required',
				// 'invoice_date.required' => 'Invoice Date is required',
				// 'round_off_amount.required' => 'Round Off Amount is required',
			];

			$validator = Validator::make($request->all(), [
				'branch_id' => [
					'required:true',
				],
				'sbu_id' => [
					'required:true',
				],
				'category_id' => [
					'required:true',
				],
				'address_id' => [
					'required:true',
				],
				// 'sub_category_id' => [
				// 	'required:true',
				// ],
				// 'invoice_date' => [
				// new RequiredIf($request->type_id == 1060 || $request->type_id == 1061),
				// ],
				'document_date' => [
					'required:true',
				],
				'customer_id' => [
					'required:true',
				],
				'to_account_type_id' => [
					'required:true',
				],
				'proposal_attachments.*' => [
					'required:true',
					// 'mimes:jpg,jpeg,png,bmp',
				],
				// 'is_reverse_charge_applicable' => [
				// 	'required:true',
				// ],
				// 'po_reference_number' => [
				// 	'required:true',
				// ],
				// 'invoice_number' => [
				// new RequiredIf($request->type_id == 1060 || $request->type_id == 1061),
				// ],
				// 'round_off_amount' => [
				// 	'required:true',
				// ],
				// 'invoice_date' => [
				// 	'required:true',
				// ],
			], $error_messages);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
			}

			//SERIAL NUMBER GENERATION & VALIDATION
			if (!$request->id) {
				//GET FINANCIAL YEAR ID BY DOCUMENT DATE
				if (date('m', strtotime($request->document_date)) > 3) {
					$document_date_year = date('Y', strtotime($request->document_date)) + 1;
				} else {
					$document_date_year = date('Y', strtotime($request->document_date));
				}
				$financial_year = FinancialYear::where('from', $document_date_year)
					->where('company_id', Auth::user()->company_id)
					->first();
				if (!$financial_year) {
					return response()->json(['success' => false, 'errors' => ['Fiancial Year Not Found']]);
				}
				$branch = Outlet::where('id', $request->branch_id)->first();

				if ($request->type_id == 1061) {
					//DN
					$serial_number_category = 5;
				} elseif ($request->type_id == 1060) {
					//CN
					$serial_number_category = 4;
				} elseif ($request->type_id == 1062) {
					//INV
					$serial_number_category = 125;
				}

				$sbu = Sbu::find($request->sbu_id);
				if (!$sbu) {
					return response()->json(['success' => false, 'errors' => ['SBU Not Found']]);
				}

				if (!$sbu->business_id) {
					return response()->json(['success' => false, 'errors' => ['Business Not Found']]);
				}

				//OUTLET BASED CODE
				// $generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, $branch->id, $sbu);

				//ONLY FOR SCRAP INVOICE
				if ($request->category_id == 4) {
					if ($request->type_id == 1061) {
						//DN
						$serial_number_category = 128;
					} elseif ($request->type_id == 1060) {
						//CN
						$serial_number_category = 127;
					} elseif ($request->type_id == 1062) {
						//INV
						$serial_number_category = 126;
					}
					$generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, NULL, NULL, NULL);
				} else {
					//STATE BUSINESS BASED CODE
					$generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, NULL, NULL, $sbu->business_id);
				}
				// dd($generateNumber);
				$generateNumber['service_invoice_id'] = $request->id;

				$error_messages_1 = [
					'number.required' => 'Serial number is required',
					'number.unique' => 'Serial number is already taken',
				];

				$validator_1 = Validator::make($generateNumber, [
					'number' => [
						'required',
						'unique:service_invoices,number,' . $request->id . ',id,company_id,' . Auth::user()->company_id,
					],
				], $error_messages_1);

				if ($validator_1->fails()) {
					return response()->json(['success' => false, 'errors' => $validator_1->errors()->all()]);
				}

			}

			//VALIDATE SERVICE INVOICE ITEMS
			if (!$request->service_invoice_items) {
				return response()->json(['success' => false, 'errors' => ['Service invoice item is required']]);
			}
			$approval_status = Entity::select('entities.name')->where('company_id', Auth::user()->company_id)->where('entity_type_id', 18)->first();

			if ($request->id) {
				$service_invoice = ServiceInvoice::find($request->id);
				$service_invoice->updated_at = date("Y-m-d H:i:s");
				$service_invoice->updated_by_id = Auth()->user()->id;
				$message = 'Service invoice updated successfully';
			} else {
				$service_invoice = new ServiceInvoice();
				$service_invoice->created_at = date("Y-m-d H:i:s");
				$service_invoice->created_by_id = Auth()->user()->id;
				$service_invoice->number = $generateNumber['number'];
				if ($approval_status != '') {
					$service_invoice->status_id = 1; //$approval_status->name;
				} else {
					return response()->json(['success' => false, 'errors' => ['Initial CN/DN Status has not mapped.!']]);
				}
				$message = 'Service invoice added successfully';
			}
			if ($request->type_id == 1061) {
				$service_invoice->is_cn_created = 0;
			} elseif ($request->type_id == 1060) {
				$service_invoice->is_cn_created = 1;
			}

			$service_invoice->type_id = $request->type_id;
			$service_invoice->fill($request->all());
			$service_invoice->round_off_amount = abs($request->round_off_amount);
			// $service_invoice->invoice_date = date('Y-m-d H:i:s');
			$service_invoice->company_id = Auth::user()->company_id;
			$service_invoice->address_id = $request->address_id;
			$service_invoice->save();
			// dd($service_invoice);
			$approval_levels = Entity::select('entities.name')->where('company_id', Auth::user()->company_id)->where('entity_type_id', 19)->first();
			// $approval_levels = ApprovalLevel::where('approval_type_id', 1)->first();
			if ($approval_levels != '') {
				if ($service_invoice->status_id == $approval_levels->name) {
					$r = $this->createPdf($service_invoice->id);
					if (!$r['success']) {
						DB::rollBack();
						return response()->json($r);
					}
				}
			} else {
				return response()->json(['success' => false, 'errors' => ['Final CN/DN Status has not mapped.!']]);
			}

			//REMOVE SERVICE INVOICE ITEMS
			if (!empty($request->service_invoice_item_removal_ids)) {
				$service_invoice_item_removal_ids = json_decode($request->service_invoice_item_removal_ids, true);
				ServiceInvoiceItem::whereIn('id', $service_invoice_item_removal_ids)->delete();
			}

			//SAVE SERVICE INVOICE ITEMS
			if ($request->service_invoice_items) {
				if (!empty($request->service_invoice_items)) {
					//VALIDATE UNIQUE
					// $service_invoice_items = collect($request->service_invoice_items)->pluck('service_item_id')->toArray();
					// $service_invoice_items_unique = array_unique($service_invoice_items);
					// if (count($service_invoice_items) != count($service_invoice_items_unique)) {
					// 	return response()->json(['success' => false, 'errors' => ['Service invoice items has already been taken']]);
					// }
					foreach ($request->service_invoice_items as $key => $val) {
						$service_invoice_item = ServiceInvoiceItem::firstOrNew([
							'id' => $val['id'],
						]);
						$service_invoice_item->fill($val);
						$service_invoice_item->service_invoice_id = $service_invoice->id;
						$service_invoice_item->save();

						//SAVE SERVICE INVOICE ITEMS FIELD GROUPS AND RESPECTIVE FIELDS
						$fields = Field::get()->keyBy('id');
						$service_invoice_item->eavVarchars()->sync([]);
						$service_invoice_item->eavInts()->sync([]);
						$service_invoice_item->eavDatetimes()->sync([]);
						if (isset($val['field_groups']) && !empty($val['field_groups'])) {
							foreach ($val['field_groups'] as $fg_key => $fg_value) {
								if (isset($fg_value['fields']) && !empty($fg_value['fields'])) {
									foreach ($fg_value['fields'] as $f_key => $f_value) {
										//SAVE FREE TEXT | NUMERIC TEXT FIELDS
										if ($fields[$f_value['id']]->type_id == 3 || $fields[$f_value['id']]->type_id == 4) {
											$service_invoice_item->eavVarchars()->attach(1040, ['field_group_id' => $fg_value['id'], 'field_id' => $f_value['id'], 'value' => $f_value['value']]);

										} elseif ($fields[$f_value['id']]->type_id == 2) {
											//SAVE MSDD
											$msdd_fd_value = json_decode($f_value['value']);
											if (!empty($msdd_fd_value)) {
												foreach ($msdd_fd_value as $msdd_key => $msdd_val) {
													$service_invoice_item->eavInts()->attach(1040, ['field_group_id' => $fg_value['id'], 'field_id' => $f_value['id'], 'value' => $msdd_val]);
												}
											}
										} elseif ($fields[$f_value['id']]->type_id == 7 || $fields[$f_value['id']]->type_id == 8) {
											//SAVE DATEPICKER | DATETIMEPICKER
											$dp_dtp_fd_value = date('Y-m-d H:i:s', strtotime($f_value['value']));
											$service_invoice_item->eavDatetimes()->attach(1040, ['field_group_id' => $fg_value['id'], 'field_id' => $f_value['id'], 'value' => $dp_dtp_fd_value]);

										} elseif ($fields[$f_value['id']]->type_id == 1 || $fields[$f_value['id']]->type_id == 10) {
											//SAVE SSDD | AC
											$service_invoice_item->eavInts()->attach(1040, ['field_group_id' => $fg_value['id'], 'field_id' => $f_value['id'], 'value' => $f_value['value']]);
										} elseif ($fields[$f_value['id']]->type_id == 9) {
											//SAVE SWITCH
											$fd_switch_val = (($f_value['value'] == 'Yes') ? 1 : 0);
											$service_invoice_item->eavInts()->attach(1040, ['field_group_id' => $fg_value['id'], 'field_id' => $f_value['id'], 'value' => $fd_switch_val]);
										}
									}
								}
							}
						}

						//SAVE SERVICE INVOICE ITEM TAX
						if (!empty($val['taxes'])) {
							//VALIDATE UNIQUE
							$service_invoice_item_taxes = collect($val['taxes'])->pluck('tax_id')->toArray();
							$service_invoice_item_taxes_unique = array_unique($service_invoice_item_taxes);
							if (count($service_invoice_item_taxes) != count($service_invoice_item_taxes_unique)) {
								return response()->json(['success' => false, 'errors' => ['Service invoice item taxes has already been taken']]);
							}
							$service_invoice_item->taxes()->sync([]);
							foreach ($val['taxes'] as $tax_key => $tax_val) {
								$service_invoice_item->taxes()->attach($tax_val['tax_id'], ['percentage' => $tax_val['percentage'], 'amount' => $tax_val['amount']]);
							}
						}
					}
				}
			}
			//ATTACHMENT REMOVAL
			$attachment_removal_ids = json_decode($request->attachment_removal_ids);
			if (!empty($attachment_removal_ids)) {
				Attachment::whereIn('id', $attachment_removal_ids)->forceDelete();
			}

			//SAVE ATTACHMENTS
			$attachement_path = storage_path('app/public/service-invoice/attachments/');
			Storage::makeDirectory($attachement_path, 0777);
			if (!empty($request->proposal_attachments)) {
				foreach ($request->proposal_attachments as $key => $proposal_attachment) {
					$value = rand(1, 100);
					$image = $proposal_attachment;
					$extension = $image->getClientOriginalExtension();
					$name = $service_invoice->id . 'service_invoice_attachment' . $value . '.' . $extension;
					$proposal_attachment->move(storage_path('app/public/service-invoice/attachments/'), $name);
					$attachement = new Attachment;
					$attachement->attachment_of_id = 221;
					$attachement->attachment_type_id = 241;
					$attachement->entity_id = $service_invoice->id;
					$attachement->name = $name;
					$attachement->save();
				}
			}

			DB::commit();
			// dd($service_invoice->id);
			return response()->json(['success' => true, 'message' => $message, 'service_invoice_id' => $service_invoice->id]);
		} catch (Exception $e) {
			DB::rollBack();
			// dd($e->getMessage());
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}

	public function createPdf($service_invoice_id) {
		// dd($service_invoice_id);
		$errors = [];

		$service_invoice = $service_invoice_pdf = ServiceInvoice::with([
			'company',
			// 'customer',
			'toAccountType',
			'address',
			'outlets',
			'outlets.primaryAddress',
			'outlets.region',
			'sbus',
			'serviceInvoiceItems',
			'serviceInvoiceItems.serviceItem',
			'serviceInvoiceItems.eavVarchars',
			'serviceInvoiceItems.eavInts',
			'serviceInvoiceItems.eavDatetimes',
			'serviceInvoiceItems.eInvoiceUom',
			'serviceInvoiceItems.serviceItem.taxCode',
			'serviceInvoiceItems.taxes',
		])->find($service_invoice_id);
		// dd($service_invoice);
		$r = $service_invoice->exportToAxapta();
		if (!$r['success']) {
			return $r;
		}
		// dd('stop');
		//ADDED FOR QUEUE METHOD STRAT
		// CreatePdfCnDn::dispatch($service_invoice_id, Auth::user()->company_id, Auth::user()->id);
		// $service_invoice = ServiceInvoice::find($service_invoice_id);
		// $service_invoice->status_id = 3; //IN PROGRESS
		// $service_invoice->save();
		//ADDED FOR QUEUE METHOD END

		$service_invoice->customer;
		$service_invoice->address;
		// $service_invoice->customer->primaryAddress;
		// dd($service_invoice->outlets->primaryAddress->country);
		$service_invoice->company->formatted_address = $service_invoice->company->primaryAddress ? $service_invoice->company->primaryAddress->getFormattedAddress() : 'NA';
		// $service_invoice->outlets->formatted_address = $service_invoice->outlets->primaryAddress ? $service_invoice->outlets->primaryAddress->getFormattedAddress() : 'NA';
		$service_invoice->outlets = $service_invoice->outlets ? $service_invoice->outlets : 'NA';
		// $service_invoice->customer->formatted_address = $service_invoice->customer->primaryAddress ? $service_invoice->customer->primaryAddress->address_line1 : 'NA';
		$service_invoice->customer->formatted_address = $service_invoice->address ? $service_invoice->address->address_line1 : 'NA';

		if ($service_invoice->to_account_type_id == 1440) {
			// $city = City::where('name', $service_invoice->customer->city)->first();
			// dd($city);
			// if ($city) {
			$state = State::find($service_invoice->address ? $service_invoice->address->state_id : NULL);
			$service_invoice->address->state_code = $state ? $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-' : '-';
			// } else {
			// $service_invoice->customer->state_code = '-';
			// }
		} else {
			$state = State::find($service_invoice->address ? $service_invoice->address->state_id : NULL);
			// $state = State::find($service_invoice->customer->primaryAddress ? $service_invoice->customer->primaryAddress->state_id : NULL);
			$service_invoice->address->state_code = $state ? $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-' : '-';

			// dd($service_invoice->customer_id);
			// $address = Address::with(['city', 'state', 'country'])->where('address_of_id', 21)->where('entity_id', $service_invoice->customer_id)->first();
			// $address = Address::with(['city', 'state', 'country'])->find($service_invoice->address_id);
			// // dd($address);
			// if ($address) {
			// 	$service_invoice->customer->address .= $address->address_line1 ? $address->address_line1 . ', ' : '';
			// 	$service_invoice->customer->address .= $address->address_line2 ? $address->address_line2 . ', ' : '';
			// 	$service_invoice->customer->address .= $address->city ? $address->city->name . ', ' : '';
			// 	$service_invoice->customer->address .= $address->state ? $address->state->name . ', ' : '';
			// 	$service_invoice->customer->address .= $address->country ? $address->country->name . ', ' : '';
			// 	$service_invoice->customer->address .= $address->pincode ? $address->pincode . '.' : '';
			// } else {
			// 	$service_invoice->customer->address = '';
			// }
		}
		// dd(1);
		// dd($service_invoice);
		$fields = Field::withTrashed()->get()->keyBy('id');

		if (count($service_invoice->serviceInvoiceItems) > 0) {
			$array_key_replace = [];
			foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
				$taxes = $serviceInvoiceItem->taxes;
				$type = $serviceInvoiceItem->serviceItem;
				foreach ($taxes as $array_key_replace => $tax) {
					$serviceInvoiceItem[$tax->name] = $tax;
				}
				//dd($type->sac_code_id);
			}
			//Field values
			$item_count = 0;
			$item_count_with_tax_code = 0;
			$gst_total = 0;
			foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
				// dd($serviceInvoiceItem->serviceItem->code);
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
				if ($serviceInvoiceItem->serviceItem->sac_code_id) {
					$item_count_with_tax_code++;
				}
				//PUSH TOTAL FIELD GROUPS
				$serviceInvoiceItem->field_groups = $field_group_val;
				$item_count++;
			}
		}
		// dd($item_count, $item_count_with_tax_code);
		//dd($service_invoice->type_id);
		$type = $serviceInvoiceItem->serviceItem;
		if (!empty($type->sac_code_id) && ($service_invoice->type_id == 1060)) {
			$service_invoice->sac_code_status = 'CREDIT NOTE(CRN)';
			$service_invoice->document_type = 'CRN';
		} elseif (empty($type->sac_code_id) && ($service_invoice->type_id == 1060)) {
			$service_invoice->sac_code_status = 'FINANCIAL CREDIT NOTE';
			$service_invoice->document_type = 'CRN';
		} elseif ($service_invoice->type_id == 1061) {
			$service_invoice->sac_code_status = 'Tax Invoice(DBN)';
			$service_invoice->document_type = 'DBN';
		} else {
			$service_invoice->sac_code_status = 'Invoice(INV)';
			$service_invoice->document_type = 'INV';
		}

		if ($service_invoice->type_id == 1060) {
			$service_invoice->type = 'CRN';
		} elseif ($service_invoice->type_id == 1061) {
			$service_invoice->type = 'DBN';
		} elseif ($service_invoice->type_id == 1062) {
			$service_invoice->type = 'INV';
		}

		if ($service_invoice->total > $service_invoice->final_amount) {
			$service_invoice->round_off_amount = number_format(($service_invoice->final_amount - $service_invoice->total), 2);
		} elseif ($service_invoice->total < $service_invoice->final_amount) {
			$service_invoice->round_off_amount;
		} else {
			$service_invoice->round_off_amount = 0;
		}
		// dd($service_invoice->round_off_amount);

		// if (strlen(preg_replace('/\r|\n|:|"/', ",", $service_invoice->address->pincode))) {
		// 	$errors[] = 'Customer Pincode Required. Customer Pincode Not Found!';
		// 	return [
		// 		'success' => false,
		// 		'errors' => ['Customer Pincode Required. Customer Pincode Not Found!'],
		// 	];
		// 	// DB::commit();
		// }

		// if ($service_invoice->customer->gst_number && ($item_count == $item_count_with_tax_code)) {
		if ($service_invoice->address->gst_number && ($item_count == $item_count_with_tax_code)) {
			//----------// ENCRYPTION START //----------//
			if (empty($service_invoice->address->pincode)) {
				$errors[] = 'Customer Pincode Required. Customer Pincode Not Found!';
				return [
					'success' => false,
					'errors' => ['Customer Pincode Required. Customer Pincode Not Found!'],
				];
			}

			if (empty($service_invoice->address->state_id)) {
				$errors[] = 'Customer State Required. Customer State Not Found!';
				return [
					'success' => false,
					'errors' => ['Customer State Required. Customer State Not Found!'],
				];
			}

			if ($service_invoice->address) {
				if (strlen(preg_replace('/\r|\n|:|"/', ",", $service_invoice->address->address_line1)) >= 100) {
					$errors[] = 'Customer Address Maximum Allowed Length 100!';
					return [
						'success' => false,
						'errors' => ['Customer Address Maximum Allowed Length 100!'],
					];
					// DB::commit();
				}
			}

			// $service_invoice->irnCreate($service_invoice_id);
			// RSA ENCRYPTION
			$rsa = new Crypt_RSA;

			$public_key = 'MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAxqHazGS4OkY/bDp0oklL+Ser7EpTpxyeMop8kfBlhzc8dzWryuAECwu8i/avzL4f5XG/DdSgMz7EdZCMrcxtmGJlMo2tUqjVlIsUslMG6Cmn46w0u+pSiM9McqIvJgnntKDHg90EIWg1BNnZkJy1NcDrB4O4ea66Y6WGNdb0DxciaYRlToohv8q72YLEII/z7W/7EyDYEaoSlgYs4BUP69LF7SANDZ8ZuTpQQKGF4TJKNhJ+ocmJ8ahb2HTwH3Ol0THF+0gJmaigs8wcpWFOE2K+KxWfyX6bPBpjTzC+wQChCnGQREhaKdzawE/aRVEVnvWc43dhm0janHp29mAAVv+ngYP9tKeFMjVqbr8YuoT2InHWFKhpPN8wsk30YxyDvWkN3mUgj3Q/IUhiDh6fU8GBZ+iIoxiUfrKvC/XzXVsCE2JlGVceuZR8OzwGrxk+dvMnVHyauN1YWnJuUTYTrCw3rgpNOyTWWmlw2z5dDMpoHlY0WmTVh0CrMeQdP33D3LGsa+7JYRyoRBhUTHepxLwk8UiLbu6bGO1sQwstLTTmk+Z9ZSk9EUK03Bkgv0hOmSPKC4MLD5rOM/oaP0LLzZ49jm9yXIrgbEcn7rv82hk8ghqTfChmQV/q+94qijf+rM2XJ7QX6XBES0UvnWnV6bVjSoLuBi9TF1ttLpiT3fkCAwEAAQ=='; //PROVIDE FROM BDO COMPANY

			// $clientid = "prakashr@featsolutions.in"; //PROVIDE FROM BDO COMPANY
			$clientid = "amutha@sundarammotors.com"; //PROVIDE FROM BDO COMPANY
			// $clientid = "61b27a26bd86cbb93c5c11be0c2856"; //LIVE
			// dump('clientid ' . $clientid);

			$rsa->loadKey($public_key);
			$rsa->setEncryptionMode(2);
			// $client_secret_key = 'BBAkBDB0YzZiYThkYTg4ZDZBBDJjZBUyBGFkBBB0BWB='; // CLIENT SECRET KEY
			$client_secret_key = 'TQAkSDQ0YzZiYTTkYTg4ZDZSSDJjZSUySGFkSSQ0SWQ='; // CLIENT SECRET KEY
			// $client_secret_key = '7dd55886594bccadb03c48eb3f448e'; // LIVE
			$ClientSecret = $rsa->encrypt($client_secret_key);
			$clientsecretencrypted = base64_encode($ClientSecret);
			// dump('ClientSecret ' . $clientsecretencrypted);

			$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			$app_secret_key = substr(str_shuffle($characters), 0, 32); // RANDOM KEY GENERATE
			// $app_secret_key = 'Rdp5EB5w756dVph0C3jCXY1K6RPC6RCD'; // RANDOM KEY GENERATE
			$AppSecret = $rsa->encrypt($app_secret_key);
			$appsecretkey = base64_encode($AppSecret);
			// dump('appsecretkey ' . $appsecretkey);

			$bdo_login_url = 'https://sandboxeinvoiceapi.bdo.in/bdoauth/bdoauthenticate';
			// $bdo_login_url = 'https://einvoiceapi.bdo.in/bdoauth/bdoauthenticate'; // LIVE

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
			// dd($server_output);

			// Get the POST request header status
			$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			// If header status is not Created or not OK, return error message
			if ($status != 200) {
				return [
					'success' => false,
					'errors' => ["Conection Error in BDO Login!"],
				];
				$errors[] = 'Conection Error in BDO Login!';
				// DB::commit();
				// return response()->json([
				//  'success' => false,
				//  'error' => 'call to URL $bdo_login_url failed with status $status',
				//  'errors' => ["response " . $server_output . ", curl_error " . curl_error($ch) . ", curl_errno " . curl_errno($ch)],
				// ]);
			}

			curl_close($ch);

			$bdo_login_check = json_decode($server_output);

			$api_params = [
				'type_id' => $service_invoice->type_id,
				'entity_number' => $service_invoice->number,
				'entity_id' => $service_invoice->id,
				'url' => $bdo_login_url,
				'src_data' => $params,
				'response_data' => $server_output,
				'user_id' => Auth::user()->id,
				'status_id' => $bdo_login_check->status == 0 ? 11272 : 11271,
				'errors' => !empty($errors) ? NULL : json_encode($errors),
				'created_by_id' => Auth::user()->id,
			];

			if ($bdo_login_check->status == 0) {
				$api_params['message'] = 'Login Failed!';
				$api_logs[0] = $api_params;
				return [
					'success' => false,
					'errors' => [$bdo_login_check->ErrorMsg],
					'api_logs' => $api_logs,
				];
			}
			$api_params['message'] = 'Login Success!';

			$api_logs[1] = $api_params;
			// ServiceInvoice::apiLogs($api_params);

			$expiry = $bdo_login_check->expiry;
			$bdo_authtoken = $bdo_login_check->bdo_authtoken;
			$status = $bdo_login_check->status;
			$bdo_sek = $bdo_login_check->bdo_sek;

			//DECRYPT WITH APP KEY AND BDO SEK KEY
			$decrypt_data_with_bdo_sek = self::decryptAesData($app_secret_key, $bdo_sek);
			if (!$decrypt_data_with_bdo_sek) {
				$errors[] = 'Decryption Error!';
				return response()->json(['success' => false, 'error' => 'Decryption Error!']);
			}
			// dd($decrypt_data_with_bdo_sek);

			//ITEm
			$items = [];
			$sno = 1;
			$total_invoice_amount = 0;
			$cgst_total = 0;
			$sgst_total = 0;
			$igst_total = 0;
			$cgst_amt = 0;
			$sgst_amt = 0;
			$igst_amt = 0;
			$tcs_total = 0;
			foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
				$item = [];
				// dd($serviceInvoiceItem);

				//GET TAXES
				$taxes = Tax::getTaxes($serviceInvoiceItem->service_item_id, $service_invoice->branch_id, $service_invoice->customer_id, $service_invoice->to_account_type_id);
				if (!$taxes['success']) {
					$errors[] = $taxes['error'];
					// return response()->json(['success' => false, 'error' => $taxes['error']]);
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
					$errors[] = 'Service Item not found';
					// return response()->json(['success' => false, 'error' => 'Service Item not found']);
				}

				//TAX CALC AND PUSH
				if (!is_null($service_item->sac_code_id)) {
					if (count($service_item->taxCode->taxes) > 0) {
						foreach ($service_item->taxCode->taxes as $key => $value) {
							//FOR CGST
							if ($value->name == 'CGST') {
								$cgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
								$cgst_total += round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
							}
							//FOR CGST
							if ($value->name == 'SGST') {
								$sgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
								$sgst_total += round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
							}
							//FOR CGST
							if ($value->name == 'IGST') {
								$igst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
								$igst_total += round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
							}
						}
					}
				} else {
					return [
						'success' => false,
						'errors' => 'Item Not Mapped with Tax code!. Item Code: ' . $service_item->code,
					];
					$errors[] = 'Item Not Mapped with Tax code!. Item Code: ' . $service_item->code;
				}

				//FOR TCS TAX
				if ($service_item->tcs_percentage) {
					$gst_total = 0;
					$gst_total = $cgst_amt + $sgst_amt + $igst_amt;
					$tcs_total += round(($gst_total + $serviceInvoiceItem->sub_total) * $service_item->tcs_percentage / 100, 2);
				}

				// dd(1);

				// dump($serviceInvoiceItem->sub_total ? $serviceInvoiceItem->sub_total : 0);
				// dump(number_format($igst_total));
				// dd($cgst_total, $sgst_total, $igst_total);
				$item['SlNo'] = $sno; //Statically assumed
				$item['PrdDesc'] = $serviceInvoiceItem->serviceItem->name;
				$item['IsServc'] = "Y"; //ALWAYS Y
				$item['HsnCd'] = $serviceInvoiceItem->serviceItem->taxCode ? $serviceInvoiceItem->serviceItem->taxCode->code : null;

				//BchDtls
				$item['BchDtls']["Nm"] = null;
				$item['BchDtls']["Expdt"] = null;
				$item['BchDtls']["wrDt"] = null;

				$item['Barcde'] = null;
				$item['Qty'] = $serviceInvoiceItem->qty;
				$item['FreeQty'] = 0;
				$item['Unit'] = $serviceInvoiceItem->eInvoiceUom ? $serviceInvoiceItem->eInvoiceUom->code : "NOS";
				$item['UnitPrice'] = number_format($serviceInvoiceItem->rate ? $serviceInvoiceItem->rate : 0); //NEED TO CLARIFY
				$item['TotAmt'] = number_format($serviceInvoiceItem->sub_total ? $serviceInvoiceItem->sub_total : 0);
				$item['Discount'] = 0; //Always value will be "0"
				$item['PreTaxVal'] = number_format($serviceInvoiceItem->sub_total ? $serviceInvoiceItem->sub_total : 0);
				$item['AssAmt'] = number_format($serviceInvoiceItem->sub_total - 0);
				$item['IgstRt'] = isset($serviceInvoiceItem->IGST) ? number_format($serviceInvoiceItem->IGST->pivot->percentage) : 0;
				$item['IgstAmt'] = number_format(isset($serviceInvoiceItem->IGST) ? $serviceInvoiceItem->sub_total * $serviceInvoiceItem->IGST->pivot->percentage / 100 : 0, 2);
				$item['CgstRt'] = number_format(isset($serviceInvoiceItem->CGST) ? $serviceInvoiceItem->CGST->pivot->percentage : 0, 2);
				$item['CgstAmt'] = number_format(isset($serviceInvoiceItem->CGST) ? $serviceInvoiceItem->sub_total * $serviceInvoiceItem->CGST->pivot->percentage / 100 : 0, 2);
				$item['SgstRt'] = number_format(isset($serviceInvoiceItem->SGST) ? $serviceInvoiceItem->SGST->pivot->percentage : 0, 2);
				$item['SgstAmt'] = number_format(isset($serviceInvoiceItem->SGST) ? $serviceInvoiceItem->sub_total * $serviceInvoiceItem->SGST->pivot->percentage / 100 : 0, 2);
				$item['CesRt'] = 0;
				$item['CesAmt'] = 0;
				$item['CesNonAdvlAmt'] = 0;
				$item['StateCesRt'] = 0; //NEED TO CLARIFY IF KFC
				$item['StateCesAmt'] = 0; //NEED TO CLARIFY IF KFC
				$item['StateCesNonAdvlAmt'] = 0; //NEED TO CLARIFY IF KFC
				$item['OthChrg'] = 0;
				// $item['OthChrg'] = number_format(isset($serviceInvoiceItem->TCS) ? $serviceInvoiceItem->sub_total * $serviceInvoiceItem->TCS->pivot->percentage / 100 : 0, 2); //FOR TCS TAX
				$item['TotItemVal'] = number_format(($serviceInvoiceItem->sub_total ? $serviceInvoiceItem->sub_total : 0) + (isset($serviceInvoiceItem->IGST) ? $serviceInvoiceItem->sub_total * $serviceInvoiceItem->IGST->pivot->percentage / 100 : 0) + (isset($serviceInvoiceItem->CGST) ? $serviceInvoiceItem->sub_total * $serviceInvoiceItem->CGST->pivot->percentage / 100 : 0) + (isset($serviceInvoiceItem->SGST) ? $serviceInvoiceItem->sub_total * $serviceInvoiceItem->SGST->pivot->percentage / 100 : 0), 2);
				// + (isset($serviceInvoiceItem->TCS) ? $serviceInvoiceItem->sub_total * $serviceInvoiceItem->TCS->pivot->percentage / 100 : 0), 2); BDO Monish Told to remove item level other Charges

				$item['OrdLineRef'] = "0";
				$item['OrgCntry'] = "IN"; //Always value will be "IND"
				$item['PrdSlNo'] = null;

				//AttribDtls
				$item['AttribDtls'][] = [
					"Nm" => null,
					"Val" => null,
				];

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

				$sno++;
				$items[] = $item;

			}

			//RefDtls BELLOW
			//PrecDocDtls
			$prodoc_detail = [];
			$prodoc_detail['InvNo'] = $service_invoice->invoice_number ? $service_invoice->invoice_number : null;
			$prodoc_detail['InvDt'] = $service_invoice->invoice_date ? date('d-m-Y', strtotime($service_invoice->invoice_date)) : null;
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
			// dd(preg_replace("/\r|\n/", "", $service_invoice->customer->primaryAddress->address_line1));
			// dd($cgst_total, $sgst_total, $igst_total);

			$json_encoded_data =
				json_encode(
				array(
					'TranDtls' => array(
						'TaxSch' => "GST",
						'SupTyp' => "B2B", //ALWAYS B2B FOR REGISTER IRN
						'RegRev' => $service_invoice->is_reverse_charge_applicable == 1 ? "Y" : "N",
						'EcmGstin' => null,
						'IgstonIntra' => null, //NEED TO CLARIFY
						'supplydir' => null, //NULL ADDED 28-sep-2020 discussion "supplydir": "O"
					),
					'DocDtls' => array(
						"Typ" => $service_invoice->type,
						"No" => $service_invoice->number,
						// "No" => '23AUG2020SN159',
						"Dt" => date('d-m-Y', strtotime($service_invoice->document_date)),
					),
					'SellerDtls' => array(
						"Gstin" => $service_invoice->outlets ? ($service_invoice->outlets->gst_number ? $service_invoice->outlets->gst_number : 'N/A') : 'N/A',
						// "Gstin" => $service_invoice->outlets->gst_number,
						"LglNm" => $service_invoice->outlets ? $service_invoice->outlets->name : 'N/A',
						"TrdNm" => $service_invoice->outlets ? $service_invoice->outlets->name : 'N/A',
						"Addr1" => $service_invoice->outlets->primaryAddress ? preg_replace('/\r|\n|:|"/', ",", $service_invoice->outlets->primaryAddress->address_line1) : 'N/A',
						"Addr2" => $service_invoice->outlets->primaryAddress ? preg_replace('/\r|\n|:|"/', ",", $service_invoice->outlets->primaryAddress->address_line2) : null,
						"Loc" => $service_invoice->outlets->primaryAddress ? ($service_invoice->outlets->primaryAddress->state ? $service_invoice->outlets->primaryAddress->state->name : 'N/A') : 'N/A',
						"Pin" => $service_invoice->outlets->primaryAddress ? $service_invoice->outlets->primaryAddress->pincode : 'N/A',
						"Stcd" => $service_invoice->outlets->primaryAddress ? ($service_invoice->outlets->primaryAddress->state ? $service_invoice->outlets->primaryAddress->state->e_invoice_state_code : 'N/A') : 'N/A',
						// "Pin" => 637001,
						// "Stcd" => "33",
						"Ph" => '123456789', //need to clarify
						"Em" => null, //need to clarify
					),
					"BuyerDtls" => array(
						"Gstin" => $service_invoice->address->gst_number ? $service_invoice->address->gst_number : 'N/A', //need to clarify if available ok otherwise ?
						// "Gstin" => $service_invoice->customer->gst_number ? $service_invoice->customer->gst_number : 'N/A', //need to clarify if available ok otherwise ?
						// "Gstin" => "32ATAPM8948G1ZK", //for TN TESTING
						"LglNm" => $service_invoice->customer ? $service_invoice->customer->name : 'N/A',
						"TrdNm" => $service_invoice->customer ? $service_invoice->customer->name : null,
						// "Pos" => $service_invoice->customer->primaryAddress ? ($service_invoice->customer->primaryAddress->state ? $service_invoice->customer->primaryAddress->state->e_invoice_state_code : 'N/A') : 'N/A',
						// "Loc" => $service_invoice->customer->primaryAddress ? ($service_invoice->customer->primaryAddress->state ? $service_invoice->customer->primaryAddress->state->name : 'N/A') : 'N/A',
						"Pos" => $service_invoice->address ? ($service_invoice->address->state ? $service_invoice->address->state->e_invoice_state_code : 'N/A') : 'N/A',
						// "Pos" => "27",
						"Loc" => $service_invoice->address ? ($service_invoice->address->state ? $service_invoice->address->state->name : 'N/A') : 'N/A',

						"Addr1" => $service_invoice->address ? preg_replace('/\r|\n|:|"/', ",", $service_invoice->address->address_line1) : 'N/A',
						"Addr2" => $service_invoice->address ? preg_replace('/\r|\n|:|"/', ",", $service_invoice->address->address_line2) : null,
						"Stcd" => $service_invoice->address ? ($service_invoice->address->state ? $service_invoice->address->state->e_invoice_state_code : null) : null,
						"Pin" => $service_invoice->address ? $service_invoice->address->pincode : null,
						// "Pin" => 680001,
						// "Stcd" => "32",
						"Ph" => $service_invoice->customer->mobile_no ? $service_invoice->customer->mobile_no : null,
						"Em" => $service_invoice->customer->email ? $service_invoice->customer->email : null,
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
						'Item' => $items,
					),
					'ValDtls' => array(
						"AssVal" => number_format($service_invoice->sub_total ? $service_invoice->sub_total : 0, 2),
						"CgstVal" => number_format($cgst_total, 2),
						"SgstVal" => number_format($sgst_total, 2),
						"IgstVal" => number_format($igst_total, 2),
						"CesVal" => 0,
						"StCesVal" => 0,
						"Discount" => 0,
						"OthChrg" => number_format($tcs_total, 2),
						"RndOffAmt" => number_format($service_invoice->final_amount - $service_invoice->total, 2),
						// "RndOffAmt" => 0, // Invalid invoice round off amount ,should be  + or - RS 10.
						"TotInvVal" => number_format($service_invoice->final_amount, 2),
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

			// dump($json_encoded_data);
			// dd(1);

			//AES ENCRYPT
			//ENCRYPT WITH Decrypted BDO SEK KEY TO PLAIN TEXT AND JSON DATA
			$encrypt_data = self::encryptAesData($decrypt_data_with_bdo_sek, $json_encoded_data);
			if (!$encrypt_data) {
				$errors[] = 'IRN Encryption Error!';
				return response()->json(['success' => false, 'error' => 'IRN Encryption Error!']);
			}
			// dd($encrypt_data);

			//ENCRYPTED GIVEN DATA TO DBO
			$bdo_generate_irn_url = 'https://sandboxeinvoiceapi.bdo.in/bdoapi/public/generateIRN';
			// $bdo_generate_irn_url = 'https://einvoiceapi.bdo.in/bdoapi/public/generateIRN'; //LIVE

			$ch = curl_init($bdo_generate_irn_url);
			// Setup request to send json via POST`
			$params = json_encode(array(
				'Data' => $encrypt_data,
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
			$generate_irn_output_data = curl_exec($ch);
			// dd($generate_irn_output);

			curl_close($ch);

			$generate_irn_output = json_decode($generate_irn_output_data, true);
			// dump($generate_irn_output);
			// dd();

			// If header status is not Created or not OK, return error message

			// dd(json_encode($errors));
			$api_params = [
				'type_id' => $service_invoice->type_id,
				'entity_number' => $service_invoice->number,
				'entity_id' => $service_invoice->id,
				'url' => $bdo_generate_irn_url,
				'src_data' => $params,
				'response_data' => $generate_irn_output_data,
				'user_id' => Auth::user()->id,
				'status_id' => $bdo_login_check->status == 0 ? 11272 : 11271,
				// 'errors' => !empty($errors) ? NULL : json_encode($errors),
				'created_by_id' => Auth::user()->id,
			];

			if (is_array($generate_irn_output['Error'])) {
				$bdo_errors = [];
				$rearrange_key = 0;
				foreach ($generate_irn_output['Error'] as $key => $error) {
					// dump($rearrange_key, $error);
					$bdo_errors[$rearrange_key] = $error;
					$errors[$rearrange_key] = $error;
					$rearrange_key++;
				}
				// dump($bdo_errors);
				$api_params['errors'] = empty($errors) ? NULL : json_encode($errors);
				$api_params['message'] = 'Error GENSERATE IRN array!';

				$api_logs[2] = $api_params;

				return [
					'success' => false,
					'errors' => $bdo_errors,
					'api_logs' => $api_logs,
				];
				if ($generate_irn_output['status'] == 0) {
					$api_params['errors'] = ['Somthing Went Wrong!. Try Again Later!'];
					$api_params['message'] = 'Error Generating IRN!';
					$api_logs[5] = $api_params;
					return [
						'success' => false,
						'errors' => 'Somthing Went Wrong!. Try Again Later!',
						'api_logs' => $api_logs,
					];
				}
				// return response()->json(['success' => false, 'errors' => $bdo_errors]);
				// dd('Error: ' . $generate_irn_output['Error']['E2000']);
			} elseif (!is_array($generate_irn_output['Error'])) {
				if ($generate_irn_output['Status'] != 1) {
					$errors[] = $generate_irn_output['Error'];
					$api_params['message'] = 'Error GENSERATE IRN!';

					$api_params['errors'] = empty($errors) ? NULL : json_encode($errors);
					// DB::beginTransaction();

					// $api_log = new ApiLog;
					// $api_log->type_id = $service_invoice->type_id;
					// $api_log->entity_number = $service_invoice->number;
					// $api_log->entity_id = $service_invoice->id;
					// $api_log->url = $bdo_generate_irn_url;
					// $api_log->src_data = $params;
					// $api_log->response_data = $generate_irn_output_data;
					// $api_log->user_id = Auth::user()->id;
					// $api_log->created_by_id = Auth::user()->id;
					// $api_log->status_id = !empty($errors) ? 11272 : 11271; //FAILED //SUCCESS
					// $api_log->errors = empty($errors) ? NULL : json_encode($errors);
					// $api_log->save();
					// // dd($api_log);
					// DB::commit();
					$api_logs[3] = $api_params;

					return [
						'success' => false,
						'errors' => $generate_irn_output['Error'],
						'api_logs' => $api_logs,
					];
					// dd('Error: ' . $generate_irn_output['Error']);
				}
			}

			$api_params['message'] = 'Success GENSERATE IRN!';

			$api_params['errors'] = NULL;
			$api_logs[4] = $api_params;

			//AES DECRYPTION AFTER GENERATE IRN
			$irn_decrypt_data = self::decryptAesData($decrypt_data_with_bdo_sek, $generate_irn_output['Data']);
			// dd($irn_decrypt_data);
			if (!$irn_decrypt_data) {
				$errors[] = 'IRN Decryption Error!';
				return response()->json(['success' => false, 'error' => 'IRN Decryption Error!']);
			}

			$final_json_decode = json_decode($irn_decrypt_data);
			// dd($final_json_decode);

			if ($final_json_decode->irnStatus == 0) {
				$api_params['message'] = $final_json_decode->irnStatus;
				$api_params['errors'] = $final_json_decode->irnStatus;
				$api_logs[6] = $api_params;
				return [
					'success' => false,
					'errors' => $final_json_decode->ErrorMsg,
					'api_logs' => $api_logs,
				];
			}

			$IRN_images_des = storage_path('app/public/service-invoice/IRN_images');
			File::makeDirectory($IRN_images_des, $mode = 0777, true, true);

			$url = QRCode::text($final_json_decode->QRCode)->setSize(4)->setOutfile('storage/app/public/service-invoice/IRN_images/' . $service_invoice->number . '.png')->png();

			// $file_name = $service_invoice->number . '.png';

			$qr_attachment_path = base_path("storage/app/public/service-invoice/IRN_images/" . $service_invoice->number . '.png');
			// dump($qr_attachment_path);
			if (file_exists($qr_attachment_path)) {
				$ext = pathinfo(base_path("storage/app/public/service-invoice/IRN_images/" . $service_invoice->number . '.png'), PATHINFO_EXTENSION);
				// dump($ext);
				if ($ext == 'png') {
					$image = imagecreatefrompng($qr_attachment_path);
					// dump($image);
					$bg = imagecreatetruecolor(imagesx($image), imagesy($image));
					// dump($bg);
					imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
					imagealphablending($bg, TRUE);
					imagecopy($bg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
					// imagedestroy($image);
					$quality = 70; // 0 = worst / smaller file, 100 = better / bigger file
					imagejpeg($bg, $qr_attachment_path . ".jpg", $quality);
					// imagedestroy($bg);

					$service_invoice->qr_image = base_path("storage/app/public/service-invoice/IRN_images/" . $service_invoice->number . '.png') . '.jpg';
				}
			} else {
				$service_invoice->qr_image = '';
			}
			$get_version = json_decode($final_json_decode->Invoice);
			$get_version = json_decode($get_version->data);

			// $image = '<img src="storage/app/public/service-invoice/IRN_images/' . $final_json_decode->AckNo . '.png" title="IRN QR Image">';
			$service_invoice_save = ServiceInvoice::find($service_invoice_id);
			$service_invoice_save->irn_number = $final_json_decode->Irn;
			$service_invoice_save->qr_image = $service_invoice->number . '.png' . '.jpg';
			$service_invoice_save->ack_no = $final_json_decode->AckNo;
			$service_invoice_save->ack_date = $final_json_decode->AckDt;
			$service_invoice_save->version = $get_version->Version;
			$service_invoice_save->irn_request = $json_encoded_data;
			$service_invoice_save->irn_response = $irn_decrypt_data;

			if (!$r['success']) {
				$service_invoice_save->status_id = 2; //APPROVAL 1 PENDING
				return [
					'success' => false,
					'errors' => ['Somthing Went Wrong!'],
				];
			}

			if (count($errors) > 0) {
				$service_invoice->errors = empty($errors) ? NULL : json_encode($errors);
				$service_invoice->status_id = 6; //E-Invoice Fail
				$service_invoice->save();
				// return;
			}
			$service_invoice->errors = empty($errors) ? NULL : json_encode($errors);
			$service_invoice_save->save();

			//SEND TO PDF
			$service_invoice->version = $get_version->Version;
			$service_invoice->round_off_amount = $service_invoice->round_off_amount;
			$service_invoice->irn_number = $final_json_decode->Irn;
			$service_invoice->ack_no = $final_json_decode->AckNo;
			$service_invoice->ack_date = $final_json_decode->AckDt;

			// dd('no error');

		}
		//  else {
		// 	$service_invoice_save = ServiceInvoice::find($service_invoice_id);

		// 	if (count($errors) > 0) {
		// 		$service_invoice->errors = json_encode($errors);
		// 		$service_invoice->status_id = 6; //E-Invoice Fail
		// 		$service_invoice->save();
		// 		// return;
		// 	}
		// 	$service_invoice_save->status_id = 4; //APPROVED
		// 	$service_invoice_save->save();

		// }

		// dd('out');
		// dd($service_invoice);
		// dd('stop Encryption');
		//----------// ENCRYPTION END //----------//

		//dd($serviceInvoiceItem->field_groups);
		$this->data['service_invoice_pdf'] = $service_invoice;
		// dd($this->data['service_invoice_pdf']);

		$tax_list = Tax::where('company_id', Auth::user()->company_id)->get();
		$this->data['tax_list'] = $tax_list;
		// dd($this->data['tax_list']);
		$path = storage_path('app/public/service-invoice-pdf/');
		$pathToFile = $path . '/' . $service_invoice->number . '.pdf';
		$name = $service_invoice->number . '.pdf';
		File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

		$pdf = app('dompdf.wrapper');
		$pdf->getDomPDF()->set_option("enable_php", true);
		$pdf = $pdf->loadView('service-invoices/pdf/index', $this->data);

		// return $pdf->stream('service_invoice.pdf');
		// dd($pdf);
		// $po_file_name = 'Invoice-' . $service_invoice->number . '.pdf';

		File::put($pathToFile, $pdf->output());

		// return [
		// 	'success' => true,
		// ];
		$r['api_logs'] = [];

		return $r;
	}

	public function viewServiceInvoice($type_id, $id) {
		$service_invoice = ServiceInvoice::with([
			'attachments',
			// 'customer',
			'toAccountType',
			'address',
			'branch',
			'branch.primaryAddress',
			'sbu',
			'serviceInvoiceItems',
			'serviceInvoiceItems.eInvoiceUom',
			'serviceInvoiceItems.serviceItem',
			'serviceInvoiceItems.eavVarchars',
			'serviceInvoiceItems.eavInts',
			'serviceInvoiceItems.eavDatetimes',
			'serviceInvoiceItems.taxes',
			'serviceItemSubCategory',
			'serviceItemCategory',
			'serviceItemSubCategory.serviceItemCategory',
		])->find($id);
		$service_invoice->customer;
		if (!$service_invoice) {
			return response()->json(['success' => false, 'error' => 'Service Invoice not found']);
		}
		$service_invoice->customer->formatted_address = $service_invoice->customer->primaryAddress ? $service_invoice->customer->primaryAddress->getFormattedAddress() : 'NA';

		$fields = Field::withTrashed()->get()->keyBy('id');
		if (count($service_invoice->serviceInvoiceItems) > 0) {
			$gst_total = 0;
			foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
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
							->select('field_id as id', 'value')
							->get()
							->toArray();
						$fd_datetimes = DB::table('eav_datetime')
							->where('entity_type_id', 1040)
							->where('entity_id', $serviceInvoiceItem->id)
							->where('field_group_id', $fg_id)
							->select('field_id as id', 'value')
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
							->select(
								'field_id as id',
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

				//TAX CALC
				if (count($serviceInvoiceItem->taxes) > 0) {
					$gst_total = 0;
					foreach ($serviceInvoiceItem->taxes as $key => $value) {
						$gst_total += round($value->pivot->amount, 2);
						$serviceInvoiceItem[$value->name] = [
							'amount' => round($value->pivot->amount, 2),
							'percentage' => round($value->pivot->percentage, 2),
						];
					}
				}
				$serviceInvoiceItem->total = round($serviceInvoiceItem->sub_total, 2) + round($gst_total, 2);
				$serviceInvoiceItem->code = $serviceInvoiceItem->serviceItem->code;
				$serviceInvoiceItem->name = $serviceInvoiceItem->serviceItem->name;
			}
		}
		$service_invoice->ack_date = $service_invoice->ack_date ? date("d-m-Y H:i:s", strtotime($service_invoice->ack_date)) : NULL;

		$current_date_time = date('d-m-Y H:i:s');

		if (!empty($service_invoice->ack_date)) {
			$t1 = strtotime($service_invoice->ack_date);
			$t2 = strtotime($current_date_time);
			$diff = $t2 - $t1;
			$hours = $diff / (60 * 60);
			if ($hours < 24) {
				$service_invoice->cancel_irn = true;
			} else {
				$service_invoice->cancel_irn = false;
			}
		} else {
			$service_invoice->cancel_irn = true;
		}
		// dd($service_invoice->cancel_irn);

		$this->data['extras'] = [
			'sbu_list' => [],
			'tax_list' => Tax::select('name', 'id')->where('company_id', Auth::user()->company_id)->get(),
			'category_list' => collect(ServiceItemCategory::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
			'sub_category_list' => [],
			'uom_list' => EInvoiceUom::getList(),
		];
		$this->data['approval_status'] = ApprovalLevel::find(1);
		$this->data['service_invoice_status'] = ApprovalTypeStatus::join('service_invoices', 'service_invoices.status_id', 'approval_type_statuses.id')->where('service_invoices.company_id', Auth::user()->company_id)->where('service_invoices.id', $id)->first();
		$this->data['action'] = 'View';
		$this->data['success'] = true;
		$this->data['service_invoice'] = $service_invoice;
		return response()->json($this->data);
	}

	public function saveApprovalStatus(Request $request) {
		// dd($request->all());
		DB::beginTransaction();
		try {
			$send_approval = ServiceInvoice::with(['toAccountType', 'address'])->find($request->id);
			$send_approval->customer;
			if ($send_approval->address) {
				if ($send_approval->address->gst_number) {
					$customer_trande_name_check = Customer::getGstDetail($send_approval->address ? $send_approval->address->gst_number : NULL);
					if ($customer_trande_name_check->original['success'] == false) {
						return response()->json(['success' => false, 'errors' => [$customer_trande_name_check->original['error']]]);
					}
					// dump(trim(strtolower($customer_trande_name_check->original['trade_name'])), trim(strtolower($send_approval->customer->name)));
					if (trim(strtolower($customer_trande_name_check->original['trade_name'])) != trim(strtolower($send_approval->customer->name))) {
						return response()->json(['success' => false, 'errors' => ['Customer Name Not Matched with GSTIN Registration!']]);
					}
					$send_approval->status_id = 2; //$request->send_to_approval;
					$send_approval->updated_by_id = Auth()->user()->id;
					$send_approval->updated_at = date("Y-m-d H:i:s");
					$message = 'Approval status updated successfully';
					$send_approval->save();
				} else {
					$send_approval->status_id = 2; //$request->send_to_approval;
					$send_approval->updated_by_id = Auth()->user()->id;
					$send_approval->updated_at = date("Y-m-d H:i:s");
					$message = 'Approval status updated successfully';
					$send_approval->save();
				}
			} else {
				$send_approval->status_id = 2; //$request->send_to_approval;
				$send_approval->updated_by_id = Auth()->user()->id;
				$send_approval->updated_at = date("Y-m-d H:i:s");
				$message = 'Approval status updated successfully';
				$send_approval->save();
			}
			$approval_levels = Entity::select('entities.name')->where('company_id', Auth::user()->company_id)->where('entity_type_id', 19)->first();
			// $approval_levels = ApprovalLevel::where('approval_type_id', 1)->first();
			if ($approval_levels != '') {
				if ($send_approval->status_id == $approval_levels->name) {
					$r = $this->createPdf($send_approval->id);
					if (!$r['success']) {
						DB::rollBack();
						return response()->json($r);
					}
				}
			} else {
				return response()->json(['success' => false, 'errors' => ['Final CN/DN Status has not mapped.!']]);
			}
			DB::commit();
			return response()->json(['success' => true, 'message' => $message]);
		} catch (Exception $e) {
			DB::rollBack();
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}

	public function sendMultipleApproval(Request $request) {
		$send_for_approvals = ServiceInvoice::whereIn('id', $request->send_for_approval)->where('status_id', 1)->pluck('id')->toArray();
		$next_status = 2; //ApprovalLevel::where('approval_type_id', 1)->pluck('current_status_id')->first();
		if (count($send_for_approvals) == 0) {
			return response()->json(['success' => false, 'errors' => ['No New CN/DN Status in the list!']]);
		} else {
			DB::beginTransaction();
			try {
				foreach ($send_for_approvals as $key => $value) {
					// return $this->saveApprovalStatus($value, $next_status);
					// $send_approval = ServiceInvoice::find($value);
					$send_approval = ServiceInvoice::with(['toAccountType', 'address'])->find($value);
					$send_approval->customer;
					if ($send_approval->address) {
						if ($send_approval->address->gst_number) {
							$customer_trande_name_check = Customer::getGstDetail($send_approval->address ? $send_approval->address->gst_number : NULL);
							if ($customer_trande_name_check->original['success'] == false) {
								return response()->json(['success' => false, 'errors' => [$customer_trande_name_check->original['error']]]);
							}
							// dump(trim(strtolower($customer_trande_name_check->original['trade_name'])), trim(strtolower($send_approval->customer->name)));
							if (trim(strtolower($customer_trande_name_check->original['trade_name'])) != trim(strtolower($send_approval->customer->name))) {
								return response()->json(['success' => false, 'errors' => ['Customer Name Not Matched with GSTIN Registration!. Customer Name: ' . $send_approval->customer->name]]);
							}
							$send_approval->status_id = $next_status;
							$send_approval->updated_by_id = Auth()->user()->id;
							$send_approval->updated_at = date("Y-m-d H:i:s");
							$send_approval->save();
						} else {
							$send_approval->status_id = 2; //$request->send_to_approval;
							$send_approval->updated_by_id = Auth()->user()->id;
							$send_approval->updated_at = date("Y-m-d H:i:s");
							$message = 'Approval status updated successfully';
							$send_approval->save();
						}
					} else {
						$send_approval->status_id = $next_status;
						$send_approval->updated_by_id = Auth()->user()->id;
						$send_approval->updated_at = date("Y-m-d H:i:s");
						$send_approval->save();
					}
					// 		$send_approval->status_id = $next_status;
					// 		$send_approval->updated_by_id = Auth()->user()->id;
					// 		$send_approval->updated_at = date("Y-m-d H:i:s");
					// 		$send_approval->save();
					$approval_levels = Entity::select('entities.name')->where('company_id', Auth::user()->company_id)->where('entity_type_id', 19)->first();
					if ($approval_levels != '') {
						if ($send_approval->status_id == $approval_levels->name) {
							$r = $this->createPdf($send_approval->id);
							if (!$r['success']) {
								DB::rollBack();
								return response()->json($r);
							}
						}
					} else {
						return response()->json(['success' => false, 'errors' => ['Final CN/DN Status has not mapped.!']]);
					}
				}
				DB::commit();
				return response()->json(['success' => true, 'message' => 'Approval status updated successfully']);
			} catch (Exception $e) {
				DB::rollBack();
				return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
			}
		}
	}

	public function exportServiceInvoicesToExcel(Request $request) {
		// dd($request->all());
		ini_set('memory_limit', '-1');
		ini_set('max_execution_time', 0);

		ob_end_clean();
		$date_range = explode(" to ", $request->invoice_date);
		// $approved_status = ApprovalLevel::where('approval_type_id', 1)->pluck('next_status_id')->first();
		if ($request->export_type == "Export") {
			$query = ServiceInvoice::select('service_invoices.*')
			// ->join('service_item_sub_categories as sc', 'sc.id', 'service_invoices.sub_category_id')
				->where('document_date', '>=', date('Y-m-d', strtotime($date_range[0])))
				->where('document_date', '<=', date('Y-m-d', strtotime($date_range[1])))
				->where('service_invoices.company_id', Auth::user()->company_id)
				->where('status_id', 4)
				->where(function ($query) use ($request) {
					if ($request->invoice_number) {
						$query->where('service_invoices.number', 'like', "%" . $request->invoice_number . "%");
					}
				})
				->where(function ($query) use ($request) {
					if (!empty($request->type_id)) {
						$query->where('service_invoices.type_id', $request->type_id);
					}
				})
				->where(function ($query) use ($request) {
					if (!empty($request->branch_id)) {
						$query->where('service_invoices.branch_id', $request->branch_id);
					}
				})
				->where(function ($query) use ($request) {
					if (!empty($request->sbu_id)) {
						$query->where('service_invoices.sbu_id', $request->sbu_id);
					}
				})
				->where(function ($query) use ($request) {
					if (!empty($request->category_id)) {
						$query->where('service_invoices.category_id', $request->category_id);
						// $query->where('sc.category_id', $request->category_id);
					}
				})
			// ->where(function ($query) use ($request) {
			// 	if (!empty($request->sub_category_id)) {
			// 		$query->where('service_invoices.sub_category_id', $request->sub_category_id);
			// 	}
			// })
				->where(function ($query) use ($request) {
					if (!empty($request->customer_id)) {
						$query->where('service_invoices.customer_id', $request->customer_id);
					}
				})
				->where(function ($query) use ($request) {
					if (Entrust::can('view-own-cn-dn')) {
						$query->where('service_invoices.created_by_id', Auth::id());
					}
				})
			;
			$service_invoices = clone $query;
			$service_invoices = $service_invoices->get();
			// dd($service_invoices);
			foreach ($service_invoices as $service_invoice) {
				$service_invoice->exportToAxapta(true);
			}

			$service_invoice_ids = clone $query;

			$service_invoice_ids = $service_invoice_ids->pluck('service_invoices.id');
			// dd($service_invoice_ids);
			$axapta_records = AxaptaExport::where([
				'company_id' => Auth::user()->company_id,
				'entity_type_id' => 1400,
			])
				->whereIn('entity_id', $service_invoice_ids)
				->get()->toArray();

			// $axapta_records = [];
			foreach ($axapta_records as $key => &$axapta_record) {
				$axapta_record['TransDate'] = date('d/m/Y', strtotime($axapta_record['TransDate']));
				$axapta_record['DocumentDate'] = date('d/m/Y', strtotime($axapta_record['DocumentDate']));
				unset($axapta_record['id']);
				unset($axapta_record['company_id']);
				unset($axapta_record['entity_type_id']);
				unset($axapta_record['entity_id']);
				unset($axapta_record['created_at']);
				unset($axapta_record['updated_at']);
				$axapta_record['LineNum'] = $key + 1;
			}
			// dd($axapta_records);

			$file_name = 'cn-dn-export-' . date('Y-m-d-H-i-s');
			Excel::create($file_name, function ($excel) use ($axapta_records) {
				$excel->sheet('cn-dns', function ($sheet) use ($axapta_records) {

					$sheet->fromArray($axapta_records);
				});
			})->store('xlsx')
			//->download('xlsx')
			;

		} elseif ($request->export_type == "TCS Export") {
			$query = ServiceInvoice::with([
				'company',
				// 'customer',
				'toAccountType',
				'address',
				'outlets',
				'outlets.primaryAddress',
				'outlets.region',
				'sbus',
				'serviceInvoiceItems',
				'serviceInvoiceItems.serviceItem',
				//  => function ($query) {
				// 	$query->whereNotNull('tcs_percentage');
				// },
				'serviceInvoiceItems.eavVarchars',
				'serviceInvoiceItems.eavInts',
				'serviceInvoiceItems.eavDatetimes',
				'serviceInvoiceItems.eInvoiceUom',
				'serviceInvoiceItems.serviceItem.taxCode',
				'serviceInvoiceItems.taxes',
			])
				->where('document_date', '>=', date('Y-m-d', strtotime($date_range[0])))
				->where('document_date', '<=', date('Y-m-d', strtotime($date_range[1])))
				->where('service_invoices.company_id', Auth::user()->company_id)
				->where('status_id', 4)
			// ->get()
			;
			if (Entrust::can('tcs-export-all')) {
				$query = $query->where('service_invoices.company_id', Auth::user()->company_id);
			} elseif (Entrust::can('tcs-export-own')) {
				$query = $query->where('service_invoices.created_by_id', Auth::user()->id);
			} elseif (Entrust::can('tcs-export-outlet-based')) {
				$view_user_outlets_only = User::leftJoin('employees', 'employees.id', 'users.entity_id')
					->leftJoin('employee_outlet', 'employee_outlet.employee_id', 'employees.id')
					->leftJoin('outlets', 'outlets.id', 'employee_outlet.outlet_id')
					->where('employee_outlet.employee_id', Auth::user()->entity_id)
					->where('users.company_id', Auth::user()->company_id)
					->where('users.user_type_id', 1)
					->pluck('employee_outlet.outlet_id')
					->toArray();
				$query = $query->whereIn('service_invoices.branch_id', $view_user_outlets_only);
			}

			// dd(count($query));
			$service_invoices = clone $query;
			$service_invoices = $service_invoices->get();
			// dd($service_invoices);

			$service_invoice_header = ['Outlet', 'Bill / No.', 'Invoice date', 'Item name', 'Account Type', 'Customer / Vendor name', 'Address', 'Zip code', 'PAN number',
				// 'HSN/SAC Code',
				'Before GST amount', 'CGST amount', 'SGST amount', 'IGST amount', 'KFC amount', 'Taxable amount', 'Payment dates', 'Period', 'IT %', 'IT amount', 'Total'];
			$service_invoice_details = array();

			if (count($service_invoices) > 0) {
				// dd($service_invoice_header);
				if ($service_invoices) {
					foreach ($service_invoices as $key => $service_invoice) {
						// foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
						// 	dd($serviceInvoiceItem->serviceItem);
						// }
						// dump($service_invoice);
						$gst_total = 0;
						$cgst_amt = 0;
						$sgst_amt = 0;
						$igst_amt = 0;
						$kfc_amt = 0;
						$tcs_total = 0;
						foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {

							$taxes = Tax::getTaxes($serviceInvoiceItem->service_item_id, $service_invoice->branch_id, $service_invoice->customer_id, $service_invoice->to_account_type_id);

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
							// dd($service_item->taxCode->code);
							if (!$service_item) {
								return response()->json(['success' => false, 'error' => 'Service Item not found']);
							}
							// dd($serviceInvoiceItem->serviceItem);
							//TAX CALC AND PUSH
							if (!is_null($service_item->sac_code_id)) {
								if (count($service_item->taxCode->taxes) > 0) {
									foreach ($service_item->taxCode->taxes as $key => $value) {
										//FOR CGST
										if ($value->name == 'CGST') {
											$cgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
										}
										//FOR CGST
										if ($value->name == 'SGST') {
											$sgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
										}
										//FOR CGST
										if ($value->name == 'IGST') {
											$igst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
										}
									}

									if ($service_invoice->address->state_id) {
										// dd('in');
										if (($service_invoice->address->state_id == 3) && ($service_invoice->outlets->state_id == 3)) {
											//3 FOR KERALA
											//check customer state and outlet states are equal KL.  //add KFC tax
											if (!$service_invoice->address->gst_number) {
												//customer dont't have GST
												if (!is_null($service_item->sac_code_id)) {
													//customer have HSN and SAC Code
													$kfc_amt = round($serviceInvoiceItem->sub_total * 1 / 100, 2);
												}
											}
										}
									}
								}
							}
							//FOR TCS TAX
							if (!empty($service_item->tcs_percentage)) {
								$gst_total = 0;
								$gst_total = $cgst_amt + $sgst_amt + $igst_amt + $kfc_amt;
								$tcs_total = round(($gst_total + $serviceInvoiceItem->sub_total) * $service_item->tcs_percentage / 100, 2);
							}

							if ($serviceInvoiceItem->serviceItem && $serviceInvoiceItem->serviceItem->tcs_percentage > 0) {
								// dd($serviceInvoiceItem->sub_total);
								$service_invoice_details[] = [
									$service_invoice->outlets->code,
									$service_invoice->number,
									date('d/m/Y', strtotime($service_invoice->document_date)),
									$service_item->name,
									$service_invoice->toAccountType->name,
									$service_invoice->customer->name,
									$service_invoice->address->address_line1 . ',' . $service_invoice->address->address_line2,
									$service_invoice->address->pincode,
									$service_invoice->customer->pan_number,
									// $service_item->taxCode->code,
									(float) $serviceInvoiceItem->sub_total,
									$cgst_amt,
									$sgst_amt,
									$igst_amt,
									$kfc_amt,
									$serviceInvoiceItem->sub_total + $cgst_amt + $sgst_amt + $igst_amt + $kfc_amt,
									'-',
									'-',
									(float) $service_item->tcs_percentage,
									$tcs_total,
									$serviceInvoiceItem->sub_total + $cgst_amt + $sgst_amt + $igst_amt + $kfc_amt + $tcs_total,
								];
							}
						}
					}
				}
			}
			$file_name = 'cn-dn-tcs-export-' . date('Y-m-d-H-i-s');
			Excel::create($file_name, function ($excel) use ($service_invoice_header, $service_invoice_details) {
				$excel->sheet('cn-dn-tcs', function ($sheet) use ($service_invoice_header, $service_invoice_details) {
					$sheet->fromArray($service_invoice_details, NULL, 'A1');
					$sheet->row(1, $service_invoice_header);
					$sheet->row(1, function ($row) {
						$row->setBackground('#c4c4c4');
					});
				});
				$excel->setActiveSheetIndex(0);
			})
				->store('xlsx')
			;
		} elseif ($request->export_type == "GST Export") {
			// dd($request->gstin);
			$query = ServiceInvoice::with([
				'company',
				// 'customer',
				'toAccountType',
				'address',
				'outlets' => function ($query) use ($request) {
					$query->where('gst_number', $request->gstin);
				},
				'outlets.primaryAddress',
				'outlets.region',
				'sbus',
				'serviceInvoiceItems',
				'serviceInvoiceItems.serviceItem',
				'serviceInvoiceItems.eavVarchars',
				'serviceInvoiceItems.eavInts',
				'serviceInvoiceItems.eavDatetimes',
				'serviceInvoiceItems.eInvoiceUom',
				'serviceInvoiceItems.serviceItem.taxCode',
				'serviceInvoiceItems.taxes',
			])
				->where('document_date', '>=', date('Y-m-d', strtotime($date_range[0])))
				->where('document_date', '<=', date('Y-m-d', strtotime($date_range[1])))
				->where('service_invoices.company_id', Auth::user()->company_id)
				->where('status_id', 4)
			// ->get()
			;
			// if (Entrust::can('gst-export-all')) {
			// 	$query = $query->where('service_invoices.company_id', Auth::user()->company_id);
			// } elseif (Entrust::can('gst-export-own')) {
			// 	$query = $query->where('service_invoices.created_by_id', Auth::user()->id);
			// } elseif (Entrust::can('gst-export-outlet-based')) {
			// 	$view_user_outlets_only = User::leftJoin('employees', 'employees.id', 'users.entity_id')
			// 		->leftJoin('employee_outlet', 'employee_outlet.employee_id', 'employees.id')
			// 		->leftJoin('outlets', 'outlets.id', 'employee_outlet.outlet_id')
			// 		->where('employee_outlet.employee_id', Auth::user()->entity_id)
			// 		->where('users.company_id', Auth::user()->company_id)
			// 		->where('users.user_type_id', 1)
			// 		->pluck('employee_outlet.outlet_id')
			// 		->toArray();
			// 	$query = $query->whereIn('service_invoices.branch_id', $view_user_outlets_only);
			// }

			// dd(count($query));
			$service_invoices = clone $query;
			$service_invoices = $service_invoices->get();
			// dd($service_invoices);

			$service_invoice_header = ['Account Type', 'Customer/Vendor Code', 'Invoice No', 'Invoice Date
', 'Customer/Vendor Name', 'GSTIN', 'Billing Address', 'Invoice Value', 'HSN/SAC Code', 'Unit Of Measure', 'Qty', 'Item Taxable Value', 'CGST Rate', 'SGST Rate', 'IGST Rate', 'KFC Rate', 'CGST Amount', 'SGST Amount', 'IGST Amount', 'KFC Amount',
			];
			$service_invoice_details = array();

			if (count($service_invoices) > 0) {
				// dd($service_invoice_header);
				if ($service_invoices) {
					foreach ($service_invoices as $key => $service_invoice) {
						$gst_total = 0;
						$cgst_amt = 0;
						$sgst_amt = 0;
						$igst_amt = 0;
						$kfc_amt = 0;
						$kfc_percentage = 0;
						$cgst_percentage = 0;
						$sgst_percentage = 0;
						$igst_percentage = 0;
						foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {

							$taxes = Tax::getTaxes($serviceInvoiceItem->service_item_id, $service_invoice->branch_id, $service_invoice->customer_id, $service_invoice->to_account_type_id);

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
							// dd($service_item->taxCode->code);
							if (!$service_item) {
								return response()->json(['success' => false, 'error' => 'Service Item not found']);
							}
							// dd($serviceInvoiceItem->serviceItem);
							//TAX CALC AND PUSH
							if (!is_null($service_item->sac_code_id)) {
								if (count($service_item->taxCode->taxes) > 0) {
									foreach ($service_item->taxCode->taxes as $key => $value) {
										//FOR CGST
										if ($value->name == 'CGST') {
											$cgst_percentage = $value->pivot->percentage;
											$cgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
										}
										//FOR CGST
										if ($value->name == 'SGST') {
											$sgst_percentage = $value->pivot->percentage;
											$sgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
										}
										//FOR CGST
										if ($value->name == 'IGST') {
											$igst_percentage = $value->pivot->percentage;
											$igst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
										}
									}

									if ($service_invoice->address->state_id && $service_invoice->outlets) {
										if ($service_invoice->address->state_id == 3) {
											if (($service_invoice->address->state_id == 3) && ($service_invoice->outlets->state_id == 3)) {
												//3 FOR KERALA
												//check customer state and outlet states are equal KL.  //add KFC tax
												if (!$service_invoice->address->gst_number) {
													//customer dont't have GST
													if (!is_null($service_item->sac_code_id)) {
														$kfc_percentage = 1;
														//customer have HSN and SAC Code
														$kfc_amt = round($serviceInvoiceItem->sub_total * 1 / 100, 2);
													}
												}
											}
										}
									}
								}
							}
							if ($service_invoice->outlets) {
								// dd($service_invoice);
								$service_invoice_details[] = [
									$service_invoice->toAccountType->name,
									$service_invoice->customer->code,
									$service_invoice->number,
									date('d/m/Y', strtotime($service_invoice->document_date)),
									$service_invoice->customer->name,
									$service_invoice->address->gst_number,
									$service_invoice->address->address_line1 . ',' . $service_invoice->address->address_line2,
									$serviceInvoiceItem->sub_total + $cgst_amt + $sgst_amt + $igst_amt + $kfc_amt,
									$service_item->taxCode->code,
									$serviceInvoiceItem->eInvoiceUom->code,
									$serviceInvoiceItem->qty,
									(float) ($serviceInvoiceItem->sub_total / $serviceInvoiceItem->qty),
									$cgst_percentage,
									$sgst_percentage,
									$igst_percentage,
									$kfc_percentage,
									$cgst_amt,
									$sgst_amt,
									$igst_amt,
									$kfc_amt,
								];
							}
						}
					}
				}
			}
			$file_name = 'cn-dn-gst-export-' . date('Y-m-d-H-i-s');
			Excel::create($file_name, function ($excel) use ($service_invoice_header, $service_invoice_details) {
				$excel->sheet('cn-dn-tcs', function ($sheet) use ($service_invoice_header, $service_invoice_details) {
					$sheet->fromArray($service_invoice_details, NULL, 'A1');
					$sheet->row(1, $service_invoice_header);
					$sheet->row(1, function ($row) {
						$row->setBackground('#c4c4c4');
					});
				});
				$excel->setActiveSheetIndex(0);
			})
				->store('xlsx')
			;
		}
		return response()->download('storage/exports/' . $file_name . '.xlsx');
		return Storage::download(storage_path('exports/') . $file_name . '.xlsx');

		// dd($r->all(), $date_range, $service_invoice_ids, $axapta_records);

	}

	public function cancelIrn(Request $request) {
		// dd($request->all());
		$service_invoice = ServiceInvoice::find($request->id);

		$rsa = new Crypt_RSA;

		$public_key = 'MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAxqHazGS4OkY/bDp0oklL+Ser7EpTpxyeMop8kfBlhzc8dzWryuAECwu8i/avzL4f5XG/DdSgMz7EdZCMrcxtmGJlMo2tUqjVlIsUslMG6Cmn46w0u+pSiM9McqIvJgnntKDHg90EIWg1BNnZkJy1NcDrB4O4ea66Y6WGNdb0DxciaYRlToohv8q72YLEII/z7W/7EyDYEaoSlgYs4BUP69LF7SANDZ8ZuTpQQKGF4TJKNhJ+ocmJ8ahb2HTwH3Ol0THF+0gJmaigs8wcpWFOE2K+KxWfyX6bPBpjTzC+wQChCnGQREhaKdzawE/aRVEVnvWc43dhm0janHp29mAAVv+ngYP9tKeFMjVqbr8YuoT2InHWFKhpPN8wsk30YxyDvWkN3mUgj3Q/IUhiDh6fU8GBZ+iIoxiUfrKvC/XzXVsCE2JlGVceuZR8OzwGrxk+dvMnVHyauN1YWnJuUTYTrCw3rgpNOyTWWmlw2z5dDMpoHlY0WmTVh0CrMeQdP33D3LGsa+7JYRyoRBhUTHepxLwk8UiLbu6bGO1sQwstLTTmk+Z9ZSk9EUK03Bkgv0hOmSPKC4MLD5rOM/oaP0LLzZ49jm9yXIrgbEcn7rv82hk8ghqTfChmQV/q+94qijf+rM2XJ7QX6XBES0UvnWnV6bVjSoLuBi9TF1ttLpiT3fkCAwEAAQ=='; //PROVIDE FROM BDO COMPANY

		// $clientid = "prakashr@featsolutions.in"; //PROVIDE FROM BDO COMPANY
		$clientid = "amutha@sundarammotors.com"; //PROVIDE FROM BDO COMPANY
		// $clientid = "61b27a26bd86cbb93c5c11be0c2856"; //LIVE

		// dump('clientid ' . $clientid);

		$rsa->loadKey($public_key);
		$rsa->setEncryptionMode(2);
		// $client_secret_key = 'BBAkBDB0YzZiYThkYTg4ZDZBBDJjZBUyBGFkBBB0BWB='; // CLIENT SECRET KEY
		$client_secret_key = 'TQAkSDQ0YzZiYTTkYTg4ZDZSSDJjZSUySGFkSSQ0SWQ='; // CLIENT SECRET KEY
		// $client_secret_key = '7dd55886594bccadb03c48eb3f448e'; // LIVE

		$ClientSecret = $rsa->encrypt($client_secret_key);
		$clientsecretencrypted = base64_encode($ClientSecret);
		// dump('ClientSecret ' . $clientsecretencrypted);

		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$app_secret_key = substr(str_shuffle($characters), 0, 32); // RANDOM KEY GENERATE
		// $app_secret_key = 'Rdp5EB5w756dVph0C3jCXY1K6RPC6RCD'; // RANDOM KEY GENERATE
		$AppSecret = $rsa->encrypt($app_secret_key);
		$appsecretkey = base64_encode($AppSecret);
		// dump('appsecretkey ' . $appsecretkey);

		$bdo_login_url = 'https://sandboxeinvoiceapi.bdo.in/bdoauth/bdoauthenticate';
		// $bdo_login_url = 'https://einvoiceapi.bdo.in/bdoauth/bdoauthenticate'; //LIVE

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
		$server_output_data = curl_exec($ch);

		// Get the POST request header status
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// If header status is not Created or not OK, return error message
		if ($status != 200) {
			// $errors[] = curl_errno($ch);
			return [
				'success' => false,
				'errors' => curl_errno($ch),
			];
			// return response()->json([
			//  'success' => false,
			//  'error' => 'call to URL $bdo_login_url failed with status $status',
			//  'errors' => ["response " . $server_output . ", curl_error " . curl_error($ch) . ", curl_errno " . curl_errno($ch)],
			// ]);
		}
		$server_output = json_decode($server_output_data);

		DB::beginTransaction();

		$api_log = new ApiLog;
		$api_log->type_id = $service_invoice->type_id;
		$api_log->entity_number = $service_invoice->number;
		$api_log->entity_id = $service_invoice->id;
		$api_log->url = $bdo_login_url;
		$api_log->src_data = $params;
		$api_log->response_data = $server_output_data;
		$api_log->user_id = Auth::user()->id;
		$api_log->status_id = $server_output->status == 0 ? 11272 : 11271;
		$api_log->errors = curl_errno($ch);
		$api_log->created_by_id = Auth::user()->id;
		$api_log->save();

		DB::commit();

		if ($server_output->status == 0) {
			return ['success' => false, 'errors' => $server_output->ErrorMsg];
		}

		curl_close($ch);

		$expiry = $server_output->expiry;
		$bdo_authtoken = $server_output->bdo_authtoken;
		$status = $server_output->status;
		$bdo_sek = $server_output->bdo_sek;

		//DECRYPT WITH APP KEY AND BDO SEK KEY
		$decrypt_data_with_bdo_sek = self::decryptAesData($app_secret_key, $bdo_sek);
		if (!$decrypt_data_with_bdo_sek) {
			$errors[] = 'Decryption Error!';
			return response()->json(['success' => false, 'error' => 'Decryption Error!']);
		}

		$json_encoded_data =
			json_encode(
			array(
				"supplier_gstin" => $service_invoice->outlets ? ($service_invoice->outlets->gst_number ? $service_invoice->outlets->gst_number : 'N/A') : 'N/A', //FOR TESTING
				// "supplier_gstin" => "09ADDPT0274H009", //FOR TESTING
				"doc_no" => $service_invoice->number,
				// "doc_no" => "23AUG2020SN146",
				"irn_no" => $service_invoice->irn_number,
				"doc_date" => date("d-m-Y", strtotime($service_invoice->document_date)),
				"reason" => "1",
				"remark" => "Wrong Data",
			)
		);
		// dump($json_encoded_data);

		//ENCRYPT WITH Decrypted BDO SEK KEY TO PLAIN TEXT AND JSON DATA
		$encrypt_data = self::encryptAesData($decrypt_data_with_bdo_sek, $json_encoded_data);
		if (!$encrypt_data) {
			$errors[] = 'IRN Encryption Error!';
			return response()->json(['success' => false, 'error' => 'IRN Encryption Error!']);
		}
		// dd($encrypt_data);

		$bdo_cancel_irn_url = 'https://sandboxeinvoiceapi.bdo.in/bdoapi/public/cancelIRN';
		// $bdo_cancel_irn_url = 'https://einvoiceapi.bdo.in/bdoapi/public/cancelIRN'; //LIVE

		$ch = curl_init($bdo_cancel_irn_url);
		// Setup request to send json via POST`
		$params = json_encode(array(
			'Data' => $encrypt_data,
		));

		// Attach encoded JSON string to the POST fields
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		// Set the content type to application/json
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'client_id: ' . $clientid,
			'bdo_authtoken: ' . $bdo_authtoken,
			'action: CANIRN',
		));

		// Return response instead of outputting
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Execute the POST request
		$cancel_irn_output_data = curl_exec($ch);
		// dd($cancel_irn_output);

		$cancel_irn_output_encode = json_decode($cancel_irn_output_data, true);
		// dd($cancel_irn_output_encode['irnStatus']);
		// If header status is not Created or not OK, return error message

		DB::beginTransaction();

		$api_log = new ApiLog;
		$api_log->type_id = $service_invoice->type_id;
		$api_log->entity_number = $service_invoice->number;
		$api_log->entity_id = $service_invoice->id;
		$api_log->url = $bdo_cancel_irn_url;
		$api_log->src_data = $params;
		$api_log->response_data = $cancel_irn_output_data;
		$api_log->user_id = Auth::user()->id;
		$api_log->status_id = $cancel_irn_output_encode['irnStatus'] != 1 ? 11272 : 11271;
		$api_log->errors = $cancel_irn_output_encode['irnStatus'] != 1 ? $cancel_irn_output_encode['ErrorMsg'] : NULL;
		$api_log->created_by_id = Auth::user()->id;
		$api_log->save();
		// dump($aes_final_decoded_plain_text);
		DB::commit();

		if ($cancel_irn_output_encode['irnStatus'] != 1) {
			return response()->json([
				'success' => false,
				'errors' => [$cancel_irn_output_encode['ErrorMsg']],
			]);
		}

		curl_close($ch);

		$service_invoice_save = ServiceInvoice::find($request->id);
		$service_invoice_save->cancel_irn_number = $cancel_irn_output_encode['Irn'];
		$service_invoice_save->cancel_irn_number = $cancel_irn_output_encode['CancelDate'];
		$service_invoice_save->cancel_irn_request = $json_encoded_data;
		$service_invoice_save->cancel_irn_response = $cancel_irn_output_data;
		$service_invoice_save->status_id = 7; //CANCELED

		$service_invoice_save->save();

		if ($service_invoice->type_id == 1060) {
			$service_invoice->type = 'CREDIT NOTE(CRN)';
		} elseif ($service_invoice->type_id == 1061) {
			$service_invoice->type = 'DEBIT NOTE(DBN)';
		} elseif ($service_invoice->type_id == 1062) {
			$service_invoice->type = 'INVOICE(INV)';
		}

		return response()->json([
			'success' => true,
			'service_invoice' => $service_invoice_save,
			'message' => $service_invoice->type . ' Cancened Successfully!',
		]);
	}

	public function searchCustomer(Request $r) {
		// return Customer::searchCustomer($r);
		// dd(strlen($r->key));
		try {
			$key = $r->key;

			$this->soapWrapper->add('customer', function ($service) {
				$service
					->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
					->trace(true);
			});
			$params = ['ACCOUNTNUM' => $r->key];
			$getResult = $this->soapWrapper->call('customer.GetNewCustMasterDetails_Search', [$params]);
			$customer_data = $getResult->GetNewCustMasterDetails_SearchResult;
			if (empty($customer_data)) {
				return response()->json(['success' => false, 'error' => 'Customer Not Available!.']);
			}

			// Convert xml string into an object
			$xml_customer_data = simplexml_load_string($customer_data->any);
			// dd($xml_customer_data);
			// Convert into json
			$customer_encode = json_encode($xml_customer_data);

			// Convert into associative array
			$customer_data = json_decode($customer_encode, true);

			$api_customer_data = $customer_data['Table'];
			if (count($api_customer_data) == 0) {
				return response()->json(['success' => false, 'error' => 'Customer Not Available!.']);
			}
			// dd($api_customer_data);
			$list = [];
			if (isset($api_customer_data)) {
				$data = [];
				$array_count = array_filter($api_customer_data, 'is_array');
				if (count($array_count) > 0) {
					// if (count($api_customer_data) > 0) {
					foreach ($api_customer_data as $key => $customer_data) {
						$data['code'] = $customer_data['ACCOUNTNUM'];
						$data['name'] = $customer_data['NAME'];
						$data['mobile_no'] = isset($customer_data['LOCATOR']) && $customer_data['LOCATOR'] != 'Not available' ? $customer_data['LOCATOR'] : NULL;
						$data['cust_group'] = isset($customer_data['CUSTGROUP']) && $customer_data['CUSTGROUP'] != 'Not available' ? $customer_data['CUSTGROUP'] : NULL;
						$data['pan_number'] = isset($customer_data['PANNO']) && $customer_data['PANNO'] != 'Not available' ? $customer_data['PANNO'] : NULL;

						$list[] = $data;
					}
				} else {
					$data['code'] = $api_customer_data['ACCOUNTNUM'];
					$data['name'] = $api_customer_data['NAME'];
					$data['mobile_no'] = isset($api_customer_data['LOCATOR']) && $api_customer_data['LOCATOR'] != 'Not available' ? $api_customer_data['LOCATOR'] : NULL;
					$data['cust_group'] = isset($api_customer_data['CUSTGROUP']) && $api_customer_data['CUSTGROUP'] != 'Not available' ? $api_customer_data['CUSTGROUP'] : NULL;
					$data['pan_number'] = isset($api_customer_data['PANNO']) && $api_customer_data['PANNO'] != 'Not available' ? $api_customer_data['PANNO'] : NULL;

					$list[] = $data;
				}
			}
			return response()->json($list);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
		}

	}

	public function getCustomerAddress(Request $request) {
		// dd($request->all());
		try {
			$this->soapWrapper->add('address', function ($service) {
				$service
					->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
					->trace(true);
			});
			$params = ['ACCOUNTNUM' => $request->data['code']];
			$getResult = $this->soapWrapper->call('address.GetNewCustomerAddress_Search', [$params]);
			$customer_data = $getResult->GetNewCustomerAddress_SearchResult;
			if (empty($customer_data)) {
				return response()->json(['success' => false, 'error' => 'Address Not Available!.']);
			}

			// Convert xml string into an object
			$xml_customer_data = simplexml_load_string($customer_data->any);
			// dd($xml_customer_data);

			// Convert into json
			$customer_encode = json_encode($xml_customer_data);
			// Convert into associative array
			$customer_data = json_decode($customer_encode, true);
			// dd($customer_data);

			$api_customer_data = $customer_data['Table'];
			// dd($api_customer_data);
			if (count($api_customer_data) == 0) {
				return response()->json(['success' => false, 'error' => 'Address Not Available!.']);
			}

			$customer = Customer::firstOrNew(['code' => $request->data['code']]);
			$customer->company_id = Auth::user()->company_id;
			$customer->name = $request->data['name'];
			$customer->cust_group = empty($request->data['cust_group']) ? NULL : $request->data['cust_group'];
			$customer->gst_number = empty($request->data['gst_number']) ? NULL : $request->data['gst_number'];
			$customer->pan_number = empty($request->data['pan_number']) ? NULL : $request->data['pan_number'];
			$customer->mobile_no = empty($request->data['mobile_no']) ? NULL : $request->data['mobile_no'];
			$customer->address = NULL;
			$customer->city = NULL; //$customer_data['CITY'];
			$customer->zipcode = NULL; //$customer_data['ZIPCODE'];
			$customer->created_at = Carbon::now();
			$customer->save();

			$list = [];
			if ($api_customer_data) {
				$data = [];
				if (isset($api_customer_data)) {
					$array_count = array_filter($api_customer_data, 'is_array');
					if (count($array_count) > 0) {
						// dd('mu;l');
						foreach ($api_customer_data as $key => $customer_data) {

							$address = Address::firstOrNew(['entity_id' => $customer->id, 'ax_id' => $customer_data['RECID']]); //CUSTOMER
							// dd($address);
							$address->company_id = Auth::user()->company_id;
							$address->entity_id = $customer->id;
							$address->ax_id = $customer_data['RECID'];
							$address->gst_number = isset($customer_data['GST_NUMBER']) ? $customer_data['GST_NUMBER'] : NULL;
							$address->address_of_id = 24;
							$address->address_type_id = 40;
							$address->name = 'Primary Address_' . $customer_data['RECID'];
							$address->address_line1 = str_replace('""', '', $customer_data['ADDRESS']);
							$city = City::where('name', $customer_data['CITY'])->first();
							$state = State::where('code', $customer_data['STATE'])->first();
							$address->country_id = $state ? $state->country_id : NULL;
							$address->state_id = $state ? $state->id : NULL;
							$address->city_id = $city ? $city->id : NULL;
							$address->pincode = $customer_data['ZIPCODE'] == 'Not available' ? NULL : $customer_data['ZIPCODE'];
							$address->save();
							$customer_address[] = $address;
						}
					} else {
						// dd('sing');
						$address = Address::firstOrNew(['entity_id' => $customer->id, 'ax_id' => $api_customer_data['RECID']]); //CUSTOMER
						// dd($address);
						$address->company_id = Auth::user()->company_id;
						$address->entity_id = $customer->id;
						$address->ax_id = $api_customer_data['RECID'];
						$address->gst_number = isset($api_customer_data['GST_NUMBER']) ? $api_customer_data['GST_NUMBER'] : NULL;
						$address->address_of_id = 24;
						$address->address_type_id = 40;
						$address->name = 'Primary Address_' . $api_customer_data['RECID'];
						$address->address_line1 = str_replace('""', '', $api_customer_data['ADDRESS']);
						$city = City::where('name', $api_customer_data['CITY'])->first();
						// if ($city) {
						$state = State::where('code', $api_customer_data['STATE'])->first();
						$address->country_id = $state ? $state->country_id : NULL;
						$address->state_id = $state ? $state->id : NULL;
						// }
						$address->city_id = $city ? $city->id : NULL;
						$address->pincode = $api_customer_data['ZIPCODE'] == 'Not available' ? NULL : $api_customer_data['ZIPCODE'];
						$address->save();
						// dd($address);
						$customer_address[] = $address;
					}
				} else {
					$customer_address = [];
				}
			}
			return response()->json([
				'success' => true,
				'customer_address' => $customer_address,
				'customer' => $customer,
			]);
		} catch (Exception $e) {
			return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
		}
	}

	public function searchVendor(Request $r) {
		// return Customer::searchCustomer($r);
		// dd(strlen($r->key));
		try {
			$key = $r->key;

			$this->soapWrapper->add('vendor', function ($service) {
				$service
					->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
					->trace(true);
			});
			$params = ['ACCOUNTNUM' => $r->key];
			$getResult = $this->soapWrapper->call('vendor.GetNewVendMasterDetails_Search', [$params]);
			$vendor_data = $getResult->GetNewVendMasterDetails_SearchResult;
			if (empty($vendor_data)) {
				return response()->json(['success' => false, 'error' => 'Vendor Not Available!.']);
			}

			// Convert xml string into an object
			$xml_vendor_data = simplexml_load_string($vendor_data->any);
			// dd($xml_vendor_data);
			// Convert into json
			$vendor_encode = json_encode($xml_vendor_data);

			// Convert into associative array
			$vendor_data = json_decode($vendor_encode, true);

			$api_vendor_data = $vendor_data['Table'];
			if (count($api_vendor_data) == 0) {
				return response()->json(['success' => false, 'error' => 'Vendor Not Available!.']);
			}
			// dd($api_vendor_data);
			$list = [];
			if (isset($api_vendor_data)) {
				$data = [];
				$array_count = array_filter($api_vendor_data, 'is_array');
				if (count($array_count) > 0) {
					// if (count($api_vendor_data) > 0) {
					foreach ($api_vendor_data as $key => $vendor_data) {
						$data['code'] = $vendor_data['ACCOUNTNUM'];
						$data['name'] = $vendor_data['NAME'];
						$data['mobile_no'] = isset($vendor_data['LOCATOR']) && $vendor_data['LOCATOR'] != 'Not available' ? $vendor_data['LOCATOR'] : NULL;
						$data['vendor_group'] = isset($vendor_data['VENDGROUP']) && $vendor_data['VENDGROUP'] != 'Not available' ? $vendor_data['VENDGROUP'] : NULL;
						$data['pan_number'] = isset($vendor_data['PANNO']) && $vendor_data['PANNO'] != 'Not available' ? $vendor_data['PANNO'] : NULL;

						$list[] = $data;
					}
				} else {
					$data['code'] = $api_vendor_data['ACCOUNTNUM'];
					$data['name'] = $api_vendor_data['NAME'];
					$data['mobile_no'] = isset($api_vendor_data['LOCATOR']) && $api_vendor_data['LOCATOR'] != 'Not available' ? $api_vendor_data['LOCATOR'] : NULL;
					$data['vendor_group'] = isset($api_vendor_data['VENDGROUP']) && $api_vendor_data['VENDGROUP'] != 'Not available' ? $api_vendor_data['VENDGROUP'] : NULL;
					$data['pan_number'] = isset($api_vendor_data['PANNO']) && $api_vendor_data['PANNO'] != 'Not available' ? $api_vendor_data['PANNO'] : NULL;

					$list[] = $data;
				}
			}
			return response()->json($list);
		} catch (\Exception $e) {
			return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
		}

	}

	public function getVendorAddress(Request $request) {
		// dd($request->all());
		try {
			$this->soapWrapper->add('vendor_address', function ($service) {
				$service
					->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
					->trace(true);
			});
			$params = ['ACCOUNTNUM' => $request->data['code']];
			$getResult = $this->soapWrapper->call('vendor_address.GetNewVendorAddress_Search', [$params]);
			$vendor_data = $getResult->GetNewVendorAddress_SearchResult;
			if (empty($vendor_data)) {
				return response()->json(['success' => false, 'error' => 'Address Not Available!.']);
			}

			// Convert xml string into an object
			$xml_vendor_data = simplexml_load_string($vendor_data->any);
			// dd($xml_vendor_data);

			// Convert into json
			$vendor_encode = json_encode($xml_vendor_data);
			// Convert into associative array
			$vendor_data = json_decode($vendor_encode, true);
			// dd($vendor_data);

			$api_vendor_data = $vendor_data['Table'];
			// dd($api_vendor_data);
			if (count($api_vendor_data) == 0) {
				return response()->json(['success' => false, 'error' => 'Address Not Available!.']);
			}

			$vendor = Vendor::firstOrNew(['code' => $request->data['code']]);
			$vendor->company_id = Auth::user()->company_id;
			$vendor->portal_id = 1; //BPAS
			$vendor->name = $request->data['name'];
			$vendor->gstin = empty($request->data['gst_number']) ? NULL : $request->data['gst_number'];
			// $vendor->cust_group = empty($request->data['vendor_group']) ? NULL : $request->data['vendor_group'];
			// $vendor->pan_number = empty($request->data['pan_number']) ? NULL : $request->data['pan_number'];
			$vendor->mobile_no = empty($request->data['mobile_no']) ? NULL : $request->data['mobile_no'];
			$vendor->created_by = Auth::user()->id;
			$vendor->created_at = Carbon::now();
			$vendor->save();
			// dd($api_vendor_data);
			$list = [];
			if ($api_vendor_data) {
				$data = [];
				if (isset($api_vendor_data)) {
					$array_count = array_filter($api_vendor_data, 'is_array');
					if (count($array_count) > 0) {
						// dd('mu;l');
						foreach ($api_vendor_data as $key => $vendor_data) {

							$address = Address::firstOrNew(['entity_id' => $vendor->id, 'ax_id' => $vendor_data['RECID']]); //vendor
							// dd($address);
							$address->company_id = Auth::user()->company_id;
							$address->entity_id = $vendor->id;
							$address->ax_id = $vendor_data['RECID'];
							$address->gst_number = isset($vendor_data['GST_NUMBER']) ? $vendor_data['GST_NUMBER'] : NULL;
							$address->address_of_id = 21;
							$address->address_type_id = 40;
							$address->name = 'Primary Address_' . $vendor_data['RECID'];
							$address->address_line1 = str_replace('""', '', $vendor_data['ADDRESS']);
							$state = State::firstOrNew(['code' => $vendor_data['STATE']]);
							if ($state) {
								$city = City::firstOrNew(['name' => $vendor_data['CITY'], 'state_id' => $state->id]);
								$city->save();
							}
							$address->country_id = $state ? $state->country_id : NULL;
							$address->state_id = $state ? $state->id : NULL;
							$address->city_id = $city ? $city->id : NULL;
							$address->pincode = $vendor_data['ZIPCODE'] == 'Not available' ? NULL : $vendor_data['ZIPCODE'];
							$address->save();
							$vendor_address[] = $address;
						}
					} else {
						// dd('sing');
						$address = Address::firstOrNew(['entity_id' => $vendor->id, 'ax_id' => $api_vendor_data['RECID']]); //vendor
						// dd($address);
						$address->company_id = Auth::user()->company_id;
						$address->entity_id = $vendor->id;
						$address->ax_id = $api_vendor_data['RECID'];
						$address->gst_number = isset($api_vendor_data['GST_NUMBER']) ? $api_vendor_data['GST_NUMBER'] : NULL;
						$address->address_of_id = 21;
						$address->address_type_id = 40;
						$address->name = 'Primary Address_' . $api_vendor_data['RECID'];
						$address->address_line1 = str_replace('""', '', $api_vendor_data['ADDRESS']);
						$state = State::firstOrNew(['code' => $api_vendor_data['STATE']]);
						if ($state) {
							$city = City::firstOrNew(['name' => $api_vendor_data['CITY'], 'state_id' => $state->id]);
							$city->save();
						}
						$address->country_id = $state ? $state->country_id : NULL;
						$address->state_id = $state ? $state->id : NULL;
						// }
						$address->city_id = $city ? $city->id : NULL;
						$address->pincode = $api_vendor_data['ZIPCODE'] == 'Not available' ? NULL : $api_vendor_data['ZIPCODE'];
						$address->save();
						// dd($address);
						$vendor_address[] = $address;
					}
				} else {
					$vendor_address = [];
				}
			}
			return response()->json([
				'success' => true,
				'vendor_address' => $vendor_address,
				'vendor' => $vendor,
			]);
		} catch (Exception $e) {
			return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
		}
	}

	public function getGstDetails($gstin) {
		return Customer::getGstDetail($gstin);
	}

	public static function encryptAesData($encryption_key, $data) {
		$method = 'aes-256-ecb';

		$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

		$encrypted = openssl_encrypt($data, $method, $encryption_key, 0, $iv);

		return $encrypted;
	}

	public static function decryptAesData($encryption_key, $data) {
		$method = 'aes-256-ecb';

		$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

		$decrypted = openssl_decrypt(base64_decode($data), $method, $encryption_key, OPENSSL_RAW_DATA, $iv);
		return $decrypted;
	}
}