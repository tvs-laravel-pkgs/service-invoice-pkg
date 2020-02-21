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
use Abs\ServiceInvoicePkg\ServiceItemSubCategory;
use Abs\TaxPkg\Tax;
use App\Attachment;
use App\Company;
use App\Config;
use App\Customer;
use App\Entity;
use App\FinancialYear;
use App\Http\Controllers\Controller;
use App\Outlet;
use App\Sbu;
use App\User;
use Auth;
use DB;
use Entrust;
use Excel;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PDF;
use Session;
use URL;
use Validator;
use Yajra\Datatables\Datatables;

class ServiceInvoiceController extends Controller {

	public function __construct() {
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
				'outlets.code as branch',
				'sbus.name as sbu',
				'service_item_categories.name as category',
				'service_item_sub_categories.name as sub_category',
				'customers.code as customer_code',
				'customers.name as customer_name',
				'configs.name as type_name',
				'configs.id as si_type_id',
				'approval_type_statuses.status',
				'service_invoices.created_by_id'
			)
			->join('outlets', 'outlets.id', 'service_invoices.branch_id')
			->join('sbus', 'sbus.id', 'service_invoices.sbu_id')
			->join('service_item_sub_categories', 'service_item_sub_categories.id', 'service_invoices.sub_category_id')
			->join('service_item_categories', 'service_item_categories.id', 'service_item_sub_categories.category_id')
			->join('customers', 'customers.id', 'service_invoices.customer_id')
			->join('configs', 'configs.id', 'service_invoices.type_id')
			->join('approval_type_statuses', 'approval_type_statuses.id', 'service_invoices.status_id')
		// ->where('service_invoices.company_id', Auth::user()->company_id)
			->where('approval_type_statuses.approval_type_id', 1)
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
					$query->where('service_item_sub_categories.category_id', $request->category_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->sub_category_id)) {
					$query->where('service_invoices.sub_category_id', $request->sub_category_id);
				}
			})
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
		} else {
			$service_invoice_list = [];
		}
		return Datatables::of($service_invoice_list)
			->addColumn('child_checkbox', function ($service_invoice_list) {
				$checkbox = "<td><div class='table-checkbox'><input type='checkbox' id='child_" . $service_invoice_list->id . "' class='service_invoice_checkbox'/><label for='child_" . $service_invoice_list->id . "'></label></div></td>";

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
				$type_id = $service_invoice_list->si_type_id == '1060' ? 1060 : 1061;
				$img_edit = asset('public/theme/img/table/cndn/edit.svg');
				$img_view = asset('public/theme/img/table/cndn/view.svg');
				$img_download = asset('public/theme/img/table/cndn/download.svg');
				$img_delete = asset('public/theme/img/table/cndn/delete.svg');
				$path = URL::to('/storage/app/public/service-invoice-pdf');
				$output = '';
				if ($service_invoice_list->status_id == '4') {
					$output .= '<a href="#!/service-invoice-pkg/service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>
	                    	<a href="' . $path . '/' . $service_invoice_list->number . '.pdf" class=""><img class="img-responsive" src="' . $img_download . '" alt="Download" />
	                        </a>';
				} elseif ($service_invoice_list->status_id != '4') {
					$output .= '<a href="#!/service-invoice-pkg/service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>
	                    	<a href="#!/service-invoice-pkg/service-invoice/edit/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_edit . '" alt="Edit" />
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
			$service_invoice->invoice_date = date('d-m-Y');
			$this->data['action'] = 'Add';
			Session::put('sac_code_value', 'new');
		} else {
			$service_invoice = ServiceInvoice::with([
				'attachments',
				'customer',
				'branch',
				'serviceInvoiceItems',
				'serviceInvoiceItems.serviceItem',
				'serviceInvoiceItems.eavVarchars',
				'serviceInvoiceItems.eavInts',
				'serviceInvoiceItems.eavDatetimes',
				'serviceInvoiceItems.taxes',
				'serviceItemSubCategory',
			])->find($id);
			if (!$service_invoice) {
				return response()->json(['success' => false, 'error' => 'Service Invoice not found']);
			}
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
		];
		$this->data['config_values'] = Entity::where('company_id', Auth::user()->company_id)->whereIn('entity_type_id', [15, 16])->get();
		$this->data['service_invoice'] = $service_invoice;
		$this->data['success'] = true;
		return response()->json($this->data);
	}

	public function getServiceItemSubCategories($service_item_category_id) {
		return ServiceItemSubCategory::getServiceItemSubCategories($service_item_category_id);
	}

	public function getSbus($outlet_id) {
		return Sbu::getSbus($outlet_id);
	}

	public function searchCustomer(Request $r) {
		return Customer::searchCustomer($r);
	}

	public function searchField(Request $r) {
		return Field::searchField($r);
	}

	public function getCustomerDetails(Request $request) {
		return Customer::getDetails($request);
	}

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

		//GET TAXES BY CONDITIONS
		$taxes = Tax::getTaxes($request->service_item_id, $request->branch_id, $request->customer_id);
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
		//GET TAXES BY CONDITIONS
		$taxes = Tax::getTaxes($request->service_item_id, $request->branch_id, $request->customer_id);
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

		//FIELD GROUPS PUSH
		if (isset($request->field_groups)) {
			if (!empty($request->field_groups)) {
				$service_item->field_groups = $request->field_groups;
			}
		}

		$service_item->service_item_id = $service_item->id;
		$service_item->id = null;
		$service_item->description = $request->description;
		$service_item->qty = $request->qty;
		$service_item->rate = $request->amount;
		$service_item->sub_total = round(($request->qty * $request->amount), 2);
		$service_item->total = round($request->qty * $request->amount, 2) + $gst_total;

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
				'sub_category_id.required' => 'Sub Category is required',
				// 'invoice_date.required' => 'Invoice date is required',
				'document_date.required' => 'Document date is required',
				'customer_id.required' => 'Customer is required',
				'proposal_attachments.*.required' => 'Please upload an image',
				'proposal_attachments.*.mimes' => 'Only jpeg,png and bmp images are allowed',
				'number.unique' => 'Service invoice number has already been taken',
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
				'sub_category_id' => [
					'required:true',
				],
				// 'invoice_date' => [
				// 	'required:true',
				// ],
				'document_date' => [
					'required:true',
				],
				'customer_id' => [
					'required:true',
				],
				'proposal_attachments.*' => [
					'required:true',
					// 'mimes:jpg,jpeg,png,bmp',
				],
			], $error_messages);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
			}

			//SERIAL NUMBER GENERATION & VALIDATION
			if (!$request->id) {
				//GET FINANCIAL YEAR ID BY DOCUMENT DATE
				$document_date_year = date('Y', strtotime($request->document_date));
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
				}

				$sbu = Sbu::find($request->sbu_id);
				if (!$sbu) {
					return response()->json(['success' => false, 'errors' => ['SBU Not Found']]);
				}

				//GENERATE SERVICE INVOICE NUMBER
				$generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, $branch->id, $sbu);
				if (!$generateNumber['success']) {
					return response()->json(['success' => false, 'errors' => ['No Serial number found']]);
				}

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
					$service_invoice->status_id = $approval_status->name;
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
			$service_invoice->invoice_date = date('Y-m-d H:i:s');
			$service_invoice->company_id = Auth::user()->company_id;
			$service_invoice->save();
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
					$service_invoice_items = collect($request->service_invoice_items)->pluck('service_item_id')->toArray();
					$service_invoice_items_unique = array_unique($service_invoice_items);
					if (count($service_invoice_items) != count($service_invoice_items_unique)) {
						return response()->json(['success' => false, 'errors' => ['Service invoice items has already been taken']]);
					}
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
			return response()->json(['success' => true, 'message' => $message]);
		} catch (Exception $e) {
			DB::rollBack();
			// dd($e->getMessage());
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}

	public function createPdf($service_invoice_pdf_id) {
		$service_invoice_pdf = ServiceInvoice::with([
			'company',
			'customer',
			'outlets',
			'outlets.region',
			'sbus',
			'serviceInvoiceItems',
			'serviceInvoiceItems.serviceItem',
			'serviceInvoiceItems.eavVarchars',
			'serviceInvoiceItems.eavInts',
			'serviceInvoiceItems.eavDatetimes',
			'serviceInvoiceItems.serviceItem.taxCode',
			'serviceInvoiceItems.taxes',
		])->find($service_invoice_pdf_id);

		$r = $service_invoice_pdf->exportToAxapta();
		if (!$r['success']) {
			return $r;
		}

		$service_invoice_pdf->company->formatted_address = $service_invoice_pdf->company->primaryAddress ? $service_invoice_pdf->company->primaryAddress->getFormattedAddress() : 'NA';
		// $service_invoice_pdf->outlets->formatted_address = $service_invoice_pdf->outlets->primaryAddress ? $service_invoice_pdf->outlets->primaryAddress->getFormattedAddress() : 'NA';
		$service_invoice_pdf->outlets = $service_invoice_pdf->outlets ? $service_invoice_pdf->outlets : 'NA';
		$service_invoice_pdf->customer->formatted_address = $service_invoice_pdf->customer->primaryAddress ? $service_invoice_pdf->customer->primaryAddress->address_line1 : 'NA';
		// dd($service_invoice_pdf->outlets->formatted_address);
		$fields = Field::withTrashed()->get()->keyBy('id');
		if (count($service_invoice_pdf->serviceInvoiceItems) > 0) {
			$array_key_replace = [];
			foreach ($service_invoice_pdf->serviceInvoiceItems as $key => $serviceInvoiceItem) {
				$taxes = $serviceInvoiceItem->taxes;
				$type = $serviceInvoiceItem->serviceItem;
				foreach ($taxes as $array_key_replace => $tax) {
					$serviceInvoiceItem[$tax->name] = $tax;
				}
				//dd($type->sac_code_id);
			}
			//Field values
			$gst_total = 0;
			foreach ($service_invoice_pdf->serviceInvoiceItems as $key => $serviceInvoiceItem) {
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
		//dd($service_invoice_pdf->type_id);
		$type = $serviceInvoiceItem->serviceItem;
		if (!empty($type->sac_code_id) && ($service_invoice_pdf->type_id == 1060)) {
			$service_invoice_pdf->sac_code_status = 'CREDIT NOTE';
		} elseif (empty($type->sac_code_id) && ($service_invoice_pdf->type_id == 1060)) {
			$service_invoice_pdf->sac_code_status = 'FINANCIAL CREDIT NOTE';
		} else {
			$service_invoice_pdf->sac_code_status = 'Tax Invoice';
		}
		//dd($service_invoice_pdf->sac_code_status);
		//dd($serviceInvoiceItem->field_groups);
		$this->data['service_invoice_pdf'] = $service_invoice_pdf;

		$tax_list = Tax::where('company_id', Auth::user()->company_id)->get();
		$this->data['tax_list'] = $tax_list;
		// dd($this->data['service_invoice_pdf']);
		$path = storage_path('app/public/service-invoice-pdf/');
		$pathToFile = $path . '/' . $service_invoice_pdf->number . '.pdf';
		File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

		$pdf = PDF::loadView('service-invoices/pdf/index', $this->data);
		// $po_file_name = 'Invoice-' . $service_invoice_pdf->number . '.pdf';
		File::put($pathToFile, $pdf->output());
		return $r;

		// return $pdf->download($pathToFile, $headers);
	}

	public function viewServiceInvoice($type_id, $id) {
		$service_invoice = ServiceInvoice::with([
			'attachments',
			'customer',
			'branch',
			'sbu',
			'serviceInvoiceItems',
			'serviceInvoiceItems.serviceItem',
			'serviceInvoiceItems.eavVarchars',
			'serviceInvoiceItems.eavInts',
			'serviceInvoiceItems.eavDatetimes',
			'serviceInvoiceItems.taxes',
			'serviceItemSubCategory',
			'serviceItemSubCategory.serviceItemCategory',
		])->find($id);
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
		$this->data['extras'] = [
			'sbu_list' => [],
			'tax_list' => Tax::select('name', 'id')->where('company_id', Auth::user()->company_id)->get(),
			'category_list' => collect(ServiceItemCategory::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
			'sub_category_list' => [],
		];
		$this->data['approval_status'] = ApprovalLevel::where('approval_type_id', 1)->first();
		$this->data['service_invoice_status'] = ApprovalTypeStatus::join('service_invoices', 'service_invoices.status_id', 'approval_type_statuses.id')->where('service_invoices.company_id', Auth::user()->company_id)->where('service_invoices.id', $id)->first();
		$this->data['action'] = 'View';
		$this->data['success'] = true;
		$this->data['service_invoice'] = $service_invoice;
		return response()->json($this->data);
	}

	public function saveApprovalStatus(Request $request) {

		DB::beginTransaction();
		try {
			$send_approval = ServiceInvoice::find($request->id);
			$send_approval->status_id = $request->send_to_approval;
			$send_approval->updated_by_id = Auth()->user()->id;
			$send_approval->updated_at = date("Y-m-d H:i:s");
			$message = 'Approval status updated successfully';
			$send_approval->save();
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

	public function exportServiceInvoicesToExcel(Request $r) {
		ob_end_clean();
		$date_range = explode(" to ", $r->invoice_date);
		$service_invoices = ServiceInvoice::where('invoice_date', '>=', date('Y-m-d', strtotime($date_range[0])))
			->where('invoice_date', '<=', date('Y-m-d', strtotime($date_range[1])))
			->where('company_id', Auth::user()->company_id)
			->get();
		foreach ($service_invoices as $service_invoice) {
			$service_invoice->exportToAxapta(true);
		}

		$service_invoice_ids = ServiceInvoice::where('invoice_date', '>=', date('Y-m-d', strtotime($date_range[0])))
			->where('invoice_date', '<=', date('Y-m-d', strtotime($date_range[1])))
			->where('company_id', Auth::user()->company_id)
			->pluck('id');

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
		return response()->download('storage/exports/' . $file_name . '.xlsx');
		return Storage::download(storage_path('exports/') . $file_name . '.xlsx');

		// dd($r->all(), $date_range, $service_invoice_ids, $axapta_records);

	}
}