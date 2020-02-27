<?php

namespace Abs\ServiceInvoicePkg;
use Abs\ApprovalPkg\ApprovalLevel;
use Abs\ApprovalPkg\ApprovalTypeStatus;
use Abs\AttributePkg\Field;
use Abs\ServiceInvoicePkg\ServiceInvoice;
use Abs\ServiceInvoicePkg\ServiceInvoiceController;
use Abs\ServiceInvoicePkg\ServiceItem;
use Abs\ServiceInvoicePkg\ServiceItemCategory;
use Abs\TaxPkg\Tax;
use App\Config;
use App\Customer;
use App\Employee;
use App\Entity;
use App\Http\Controllers\Controller;
use App\User;
use Auth;
use DB;
use Entrust;
use Illuminate\Http\Request;
use Yajra\Datatables\Datatables;

class ServiceInvoiceApprovalController extends Controller {

	public function __construct() {
	}

	public function approvalTypeValid() {
		$this->data['approval_level'] = $approval_level = ApprovalLevel::where('approval_type_id', 1)->first();
		if (!$approval_level) {
			return response()->json(['success' => false, 'error' => 'Approval Type ID not found']);
		}
		$this->data['success'] = true;
		return response()->json($this->data);
	}

	public function getApprovalFilter() {
		$this->data['extras'] = [
			'sbu_list' => [],
			'category_list' => collect(ServiceItemCategory::select('id', 'name')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
			'sub_category_list' => [],
			'cn_dn_statuses' => collect(ApprovalTypeStatus::select('id', 'status')->where('approval_type_id', 1)->orderBy('id', 'asc')->get())->prepend(['id' => '', 'status' => 'Select CN/DN Status']),
			'type_list' => collect(Config::select('id', 'name')->where('config_type_id', 84)->get())->prepend(['id' => '', 'name' => 'Select Service Invoice Type']),
		];
		return response()->json($this->data);
	}

	public function getServiceInvoiceApprovalList(Request $request) {
		$approval_status_id = $request->approval_status_id;
		if (!empty($request->invoice_date)) {
			$document_date = explode('to', $request->invoice_date);
			$first_date_this_month = date('Y-m-d', strtotime($document_date[0]));
			$last_date_this_month = date('Y-m-d', strtotime($document_date[1]));
		} else {
			$first_date_this_month = '';
			$last_date_this_month = '';
		}
		$invoice_number_filter = $request->invoice_number;
		$cn_dn_approval_list = ServiceInvoice::withTrashed()
			->select(
				'service_invoices.id',
				'service_invoices.number',
				'service_invoices.document_date',
				'service_invoices.total as invoice_amount',
				'service_invoices.is_cn_created',
				'service_invoices.status_id',
				'service_invoices.branch_id',
				'outlets.code as branch',
				'sbus.name as sbu',
				'service_item_categories.name as category',
				'service_item_sub_categories.name as sub_category',
				'customers.code as customer_code',
				'customers.name as customer_name',
				'configs.name as type_name',
				'configs.id as si_type_id',
				'approval_levels.approval_type_id',
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
			->join('approval_types', 'approval_types.id', 'approval_type_statuses.approval_type_id')
			->join('approval_levels', 'approval_levels.approval_type_id', 'approval_types.id')
		// ->where('service_invoices.company_id', Auth::user()->company_id)
			->where('service_invoices.status_id', $approval_status_id)
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
		// dd($cn_dn_approval_list);
		if (Entrust::can('CN/DN Approval 1 View All')) {
			$cn_dn_approval_list = $cn_dn_approval_list->where('service_invoices.company_id', Auth::user()->company_id);
		} elseif (Entrust::can('CN/DN Approval 1 Outlet Based')) {
			$view_user_outlets_only = User::leftJoin('employees', 'employees.id', 'users.entity_id')
				->leftJoin('employee_outlet', 'employee_outlet.employee_id', 'employees.id')
				->leftJoin('outlets', 'outlets.id', 'employee_outlet.outlet_id')
				->where('employee_outlet.employee_id', Auth::user()->entity_id)
				->where('users.company_id', Auth::user()->company_id)
				->where('users.user_type_id', 1)
				->pluck('employee_outlet.outlet_id')
				->toArray();
			$cn_dn_approval_list = $cn_dn_approval_list->whereIn('service_invoices.branch_id', $view_user_outlets_only);
		} elseif (Entrust::can('CN/DN Approval 1 Sub Employee Based')) {
			$sub_employee_based = Employee::join('users', 'users.entity_id', 'employees.id')
				->join('service_invoices', 'service_invoices.created_by_id', 'users.id')
				->where('users.company_id', Auth::user()->company_id)
				->where('users.user_type_id', 1)
				->where('employees.reporting_to_id', Auth::user()->entity_id)
				->pluck('users.id as user_id')->toArray();
			$sub_employee_based[] = Auth::user()->id;
			$cn_dn_approval_list = $cn_dn_approval_list->whereIn('service_invoices.created_by_id', $sub_employee_based);
		} else {
			$cn_dn_approval_list = [];
		}
		return Datatables::of($cn_dn_approval_list)
			->addColumn('child_checkbox', function ($cn_dn_approval_list) {
				$checkbox = "<td><div class='table-checkbox'><input type='checkbox' id='child_" . $cn_dn_approval_list->id . "' name='child_boxes' value='" . $cn_dn_approval_list->id . "' class='service_invoice_checkbox'/><label for='child_" . $cn_dn_approval_list->id . "'></label></div></td>";

				return $checkbox;
			})
			->addColumn('invoice_amount', function ($cn_dn_approval_list) {
				if ($cn_dn_approval_list->type_name == 'CN') {
					return '-' . $cn_dn_approval_list->invoice_amount;
				} else {
					return $cn_dn_approval_list->invoice_amount;
				}

			})
			->addColumn('action', function ($cn_dn_approval_list) {
				$approval_type_id = $cn_dn_approval_list->approval_type_id;
				$type_id = $cn_dn_approval_list->si_type_id == '1060' ? 1060 : 1061;
				$img_view = asset('public/theme/img/table/cndn/view.svg');
				return '<a href="#!/service-invoice-pkg/cn-dn/approval/approval-level/' . $approval_type_id . '/view/' . $type_id . '/' . $cn_dn_approval_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>';
			})
			->rawColumns(['child_checkbox', 'action'])
			->make(true);
	}

	public function viewServiceInvoiceApproval($approval_type_id, $type_id, $id) {
		// dd('sdfsdf');
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
		$this->data['service_invoice_status'] = ApprovalTypeStatus::join('service_invoices', 'service_invoices.status_id', 'approval_type_statuses.id')->where('service_invoices.company_id', Auth::user()->company_id)->where('service_invoices.id', $id)->first();
		$this->data['action'] = 'View';
		$this->data['success'] = true;
		$this->data['service_invoice'] = $service_invoice;
		return response()->json($this->data);
	}

	public function updateApprovalStatus(Request $request) {
		DB::beginTransaction();
		try {
			$approval_status = ServiceInvoice::find($request->id);
			$approval_levels = ApprovalLevel::where('approval_type_id', 1)->first();

			if ($request->status_name == 'approve') {
				$approval_status->status_id = $approval_levels->next_status_id;
				$approval_status->comments = NULL;
				$message = 'Approved';
			} elseif ($request->status_name == 'reject') {
				$approval_status->status_id = $approval_levels->reject_status_id;
				$approval_status->comments = $request->comments;
				$message = 'Rejected';
			}
			$approval_status->updated_by_id = Auth()->user()->id;
			$approval_status->updated_at = date("Y-m-d H:i:s");
			$approval_status->save();

			$approved_status = new ServiceInvoiceController();
			$approval_levels = Entity::select('entities.name')->where('company_id', Auth::user()->company_id)->where('entity_type_id', 19)->first();

			if ($approval_levels != '') {
				if ($approval_status->status_id == $approval_levels->name) {
					$r = $approved_status->createPdf($approval_status->id);
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

	public function updateMultipleApproval(Request $request) {
		$send_for_approvals = ServiceInvoice::whereIn('id', $request->send_for_approval)->where('status_id', 2)->pluck('id')->toArray();
		$next_status = ApprovalLevel::where('approval_type_id', 1)->pluck('next_status_id')->first();
		// dd($send_for_approvals);
		if (count($send_for_approvals) == 0) {
			return response()->json(['success' => false, 'errors' => ['No Approval 1 Pending Status in the list!']]);
		} else {
			DB::beginTransaction();
			try {
				foreach ($send_for_approvals as $key => $value) {
					// return $this->saveApprovalStatus($value, $next_status);
					$send_approval = ServiceInvoice::find($value);
					$send_approval->status_id = $next_status;
					$send_approval->updated_by_id = Auth()->user()->id;
					$send_approval->updated_at = date("Y-m-d H:i:s");
					$send_approval->save();

					$approved_status = new ServiceInvoiceController();
					$approval_levels = Entity::select('entities.name')->where('company_id', Auth::user()->company_id)->where('entity_type_id', 19)->first();
					if ($approval_levels != '') {
						if ($send_approval->status_id == $approval_levels->name) {
							$r = $approved_status->createPdf($send_approval->id);
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
				return response()->json(['success' => true, 'message' => 'CN/DN Approved successfully']);
			} catch (Exception $e) {
				DB::rollBack();
				return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
			}
		}
	}
}