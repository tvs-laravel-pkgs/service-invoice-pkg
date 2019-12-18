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
use App\Customer;
use App\Entity;
use App\Http\Controllers\Controller;
use Auth;
use DB;
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

	public function getServiceInvoiceApprovalList(Request $request) {
		$approval_status_id = $request->approval_status_id;
		$cn_dn_approval_list = ServiceInvoice::withTrashed()
			->select(
				'service_invoices.id',
				'service_invoices.number',
				'service_invoices.invoice_date',
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
				'approval_levels.approval_type_id',
				'approval_type_statuses.status'
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
			->where('service_invoices.company_id', Auth::user()->company_id)
			->where('service_invoices.status_id', $approval_status_id)
			->groupBy('service_invoices.id')
			->orderBy('service_invoices.id', 'Desc');
		// dd($cn_dn_approval_list);
		return Datatables::of($cn_dn_approval_list)

			->addColumn('action', function ($cn_dn_approval_list) {
				$approval_type_id = $cn_dn_approval_list->approval_type_id;
				$type_id = $cn_dn_approval_list->si_type_id == '1060' ? 1060 : 1061;
				$img_view = asset('public/theme/img/table/cndn/view.svg');

				return '<a href="#!/service-invoice-pkg/cn-dn/approval/approval-level/' . $approval_type_id . '/view/' . $type_id . '/' . $cn_dn_approval_list->id . '" class="">
                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
                    	</a>';
			})
		// ->rawColumns(['child_checkbox', 'action'])
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
		//dd($request->all());
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
					$approved_status->createPdf($approval_status->id);
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
}