<?php

namespace Abs\ServiceInvoicePkg;
use Abs\ApprovalPkg\ApprovalTypeStatus;
use Abs\AttributePkg\Models\Field;
use Abs\ServiceInvoicePkg\ServiceInvoice;
use Abs\ServiceInvoicePkg\ServiceInvoiceController;
use Abs\ServiceInvoicePkg\ServiceItem;
use Abs\ServiceInvoicePkg\ServiceItemCategory;
use Abs\TaxPkg\Tax;
use App\Config;
use App\Customer;
use App\EInvoiceUom;
use App\Employee;
use App\Entity;
use App\Http\Controllers\Controller;
use App\User;
use App\TVSOneOrder;
use App\TVSOneOrderItem;
use File;
use QRCode;
use Auth;
use DB;
use Entrust;
use Illuminate\Http\Request;
use Yajra\Datatables\Datatables;
use phpseclib\Crypt\RSA as Crypt_RSA;
use GuzzleHttp\Client;
use App\ShortUrl;
use Abs\ServiceInvoicePkg\ServiceInvoiceItem;
use App\JobCardTvsoneCnHistory;

class ServiceInvoiceApprovalController extends Controller {

	public function __construct() {
	}

	public function approvalTypeValid() {
		// $this->data['approval_level'] = $approval_level = ApprovalLevel::where('approval_type_id', 1)->first();
		// if (!$approval_level) {
		// 	return response()->json(['success' => false, 'error' => 'Approval Type ID not found']);
		// }
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
		$cn_dn_approval_list = ServiceInvoice::
		//withTrashed()
			select(
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
				// 'approval_levels.approval_type_id',
				'approval_type_statuses.status',
				'service_invoices.created_by_id'
			)
			->join('outlets', 'outlets.id', 'service_invoices.branch_id')
			->join('sbus', 'sbus.id', 'service_invoices.sbu_id')
			->leftJoin('service_item_sub_categories', 'service_item_sub_categories.id', 'service_invoices.sub_category_id')
			->leftJoin('service_item_categories', 'service_item_categories.id', 'service_invoices.category_id')
		// ->join('customers', 'customers.id', 'service_invoices.customer_id')
			->leftJoin('customers', function ($join) {
				$join->on('customers.id', 'service_invoices.customer_id');
			})
			->leftJoin('vendors', function ($join) {
				$join->on('vendors.id', 'service_invoices.customer_id');
			})
			->join('configs', 'configs.id', 'service_invoices.type_id')
			->leftJoin('configs as to_account_type', 'to_account_type.id', 'service_invoices.to_account_type_id')
			->join('approval_type_statuses', 'approval_type_statuses.id', 'service_invoices.status_id')
			->join('approval_types', 'approval_types.id', 'approval_type_statuses.approval_type_id')
		// ->join('approval_levels', 'approval_levels.approval_type_id', 'approval_types.id')
		// ->where('service_invoices.company_id', Auth::user()->company_id)
			->where('service_invoices.status_id', 2)
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
					$query->where('service_invoices.category_id', $request->category_id);
					// $query->where('service_item_sub_categories.category_id', $request->category_id);
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
		// dd($cn_dn_approval_list);
		if (Entrust::can('CN/DN Approval 1 View All')) {
			$cn_dn_approval_list = $cn_dn_approval_list->where('service_invoices.company_id', Auth::user()->company_id);
		} elseif (Entrust::can('view-own-cn-dn-approval')) {
			$cn_dn_approval_list = $cn_dn_approval_list->where('service_invoices.created_by_id', Auth::user()->id);
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
			->addColumn('action', function ($cn_dn_approval_list) use ($approval_status_id) {
				// $approval_type_id = $cn_dn_approval_list->approval_type_id;
				// $type_id = $cn_dn_approval_list->si_type_id == '1060' ? 1060 : 1061;
				$type_id = $cn_dn_approval_list->si_type_id;
				$img_view = asset('public/theme/img/table/cndn/view.svg');
				$img_approval = asset('public/theme/img/table/cndn/approval.svg');
				$next_status = 4; //ApprovalLevel::where('approval_type_id', 1)->pluck('next_status_id')->first();
				/*<a href="#!/service-invoice-pkg/cn-dn/approval/approval-level/' . $approval_type_id . '/view/' . $type_id . '/' . $cn_dn_approval_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>*/
				//return '';
				//disabled to prevent creating repeated approval
				return '
				<a href="#!/service-invoice-pkg/cn-dn/approval/approval-level/' . $approval_status_id . '/view/' . $type_id . '/' . $cn_dn_approval_list->id . '" class="">
				                    <img class="img-responsive" src="' . $img_view . '" alt="View" />
				                	</a>
				';
				//<a href="javascript:;" data-toggle="modal" data-target="#cn-dn-approval-modal"
				//	onclick="angular.element(this).scope().sendApproval(' . $cn_dn_approval_list->id . ',' . $next_status . ')" title="Approval">
				//	<img src="' . $img_approval . '" alt="Approval" class="img-responsive">
				//	</a>
			})
			->rawColumns(['child_checkbox', 'action'])
			->make(true);
	}

	public function viewServiceInvoiceApproval($approval_type_id, $type_id, $id) {
		// dd('sdfsdf');
		$service_invoice = ServiceInvoice::with([
			'attachments',
			// 'customer',
			'toAccountType',
			'address',
			'shipAddress',
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
			'serviceItemCategory',
			'serviceItemSubCategory',
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
		$this->data['extras'] = [
			'sbu_list' => [],
			'tax_list' => Tax::select('name', 'id')->where('company_id', 1)->orderBy('id', 'ASC')->get(),
			'category_list' => collect(ServiceItemCategory::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
			'sub_category_list' => [],
			'uom_list' => EInvoiceUom::getList(),
		];
		$this->data['service_invoice_status'] = ApprovalTypeStatus::join('service_invoices', 'service_invoices.status_id', 'approval_type_statuses.id')->where('service_invoices.company_id', Auth::user()->company_id)->where('service_invoices.id', $id)->first();
		$this->data['action'] = 'View';
		$this->data['success'] = true;
		$this->data['next_status'] = 4;
		$this->data['service_invoice'] = $service_invoice;
		return response()->json($this->data);
	}

	public function updateApprovalStatus(Request $request) {
		// dd($request->all());
		DB::beginTransaction();
		try {
			$approval_status = ServiceInvoice::find($request->id);
			// $approval_levels = ApprovalLevel::where('approval_type_id', 1)->first();

			if ($request->status_name == 'approve') {
				$approval_status->status_id = 4; //$approval_levels->next_status_id;
				// $approval_status->status_id = 3;
				$approval_status->comments = NULL;
				$message = 'Approved';

				//Update TVS ONE Orders
				$tvs_one_order = TVSOneOrder::where('invoice_number',$approval_status->number)->first();
				if($tvs_one_order){
					$tvs_one_order->status_id = 12894;
					$tvs_one_order->save();
				}

				//PLATINUM MEMBERSHIP VEHICLE ADDITION
				if($tvs_one_order && count($tvs_one_order->orderItems) > 0){
					$order_item = TVSOneOrderItem::find($tvs_one_order->orderItems[0]->id);
					if($order_item->entity_type_id == 12328 && !empty($order_item->addition_vehicle_membership_id)){

						$params = [];
						$params['order_item_id'] = $order_item->id;
						TVSOneOrderItem::addAdditionVehicles($params);
					}
				}

				
			} elseif ($request->status_name == 'reject') {
				$approval_status->status_id = 5; //$approval_levels->reject_status_id;
				$approval_status->comments = $request->comments;
				$message = 'Rejected';
			}
			// $approval_status->document_date = date("Y-m-d");
			$approval_status->updated_by_id = Auth()->user()->id;
			$approval_status->updated_at = date("Y-m-d H:i:s");
			$approval_status->save();

			$approved_status = new ServiceInvoiceController();
			$approval_levels = Entity::select('entities.name')->where('company_id', Auth::user()->company_id)->where('entity_type_id', 19)->first();
			// dd($approval_levels);
			if ($approval_levels != '') {
				if ($approval_status->status_id == $approval_levels->name) {
					$invoiceItemIds = ServiceInvoiceItem::where('service_invoice_id', $approval_status->id)->pluck('id')->toArray();
					$tvsOneCnDatas = JobCardTvsoneCnHistory::whereIn('service_invoice_item_id', $invoiceItemIds)->count();
					$isTvsOneCn = $tvsOneCnDatas && $tvsOneCnDatas > 0;
					// Doc date validation based on min and max offser from entities table
					$minDate = intval(Entity::where('company_id', Auth::user()->company_id)->where('entity_type_id', 15)->pluck('name')->first());
					// if ($minDate) {
					if ($minDate && !$isTvsOneCn) {
						$minDate = $minDate * -1;
						$startDate = date('d-m-Y', strtotime($minDate . 'days'));
						$endDate = date('d-m-Y');

						$document_date = $approval_status->document_date;

						$minOffSetDate = strtotime($startDate);
						$maxOffSetDate = strtotime($endDate);
						$docOffSetDate = strtotime($document_date);
						if ($minOffSetDate > $docOffSetDate || $maxOffSetDate < $docOffSetDate)
							return response()->json(['success' => false, 'errors' => ['Doc date should be match with minimum ' . $startDate . ' and maximum of ' . $endDate]]);
					}
					// Doc date validation based on min and max offser from entities table
					$r = $approved_status->createPdf($approval_status->id);
					if (!$r['success']) {
						DB::rollBack();
						if(isset($r['errors']) && $r['errors'] == "GSP AUTHTOKEN IS NOT VALID, TRY AGAIN"){
							DB::table('bdo_auth_tokens')->where(
		                        "company_id",Auth::user()->company_id
		                    )->update(["status" => 0]);
						}
						// return response()->json($r);
					}
					if (isset($r['api_logs'])) {
						foreach ($r['api_logs'] as $api_log) {
							$api_data = ServiceInvoice::apiLogs($api_log);
						}
					}
					if (!$r['success']) {
						// DB::rollBack();
						return response()->json($r);
					}

					//SMS
					if($approval_status->status_id == 4 && !empty($tvs_one_order->customer->mobile_no)){
						$link = url('storage/app/public/service-invoice-pdf/'.$approval_status->number.'.pdf');
			            $short_url = ShortUrl::createShortLink($link, $maxlength = "5");
						$sms_params = [];
			            $sms_params['mobile_number'] = $tvs_one_order->customer->mobile_no;
			            $sms_params['sms_url'] = config('services.tvsone_sms_url');
			            $sms_params['sms_user'] = config('services.tvsone_sms_user');
			            $sms_params['sms_password'] = config('services.tvsone_sms_password');
			            $sms_params['sms_sender_id'] = config('services.tvsone_sms_sender_id');
			            $sms_params['message'] = 'Thanks for purchasing the TVSONE membership, click the link '.$short_url.' to download the Invoice – TVS';
			            tvsoneSendSMS($sms_params);
			            //test
					}
					
				}
			} else {
				return response()->json(['success' => false, 'errors' => ['Final CN/DN Status has not mapped.!']]);
			}
			DB::commit();
			return response()->json(['success' => true, 'message' => $message]);
		} catch (\Exception $e) {
			DB::rollBack();
			// return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
			return response()->json([
                'success' => false,
                'error' => 'Server Error',
                'errors' => [
                    'Error : ' . $e->getMessage() . '. Line : ' . $e->getLine() . '. File : ' . $e->getFile(),
                ],
            ]);
		}
	}

	public function updateMultipleApproval(Request $request) {
		// dd($request->all());
		$send_for_approvals = ServiceInvoice::whereIn('id', $request->send_for_approval)->where('status_id', 2)->pluck('id')->toArray();
		// $next_status = 3; //ADDED FOR QUEUE
		$next_status = 4; //ApprovalLevel::where('approval_type_id', 1)->pluck('next_status_id')->first();
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

					//Update TVS ONE Orders
					$tvs_one_order = TVSOneOrder::where('invoice_number',$send_approval->number)->first();
					if($tvs_one_order){
						$tvs_one_order->status_id = 12894;
						$tvs_one_order->save();
					}

					//PLATINUM MEMBERSHIP VEHICLE ADDITION
					if($tvs_one_order && count($tvs_one_order->orderItems) > 0){
						$order_item = TVSOneOrderItem::find($tvs_one_order->orderItems[0]->id);
						if($order_item->entity_type_id == 12328 && !empty($order_item->addition_vehicle_membership_id)){

							$params = [];
							$params['order_item_id'] = $order_item->id;
							TVSOneOrderItem::addAdditionVehicles($params);
						}
					}

					$approved_status = new ServiceInvoiceController();
					$approval_levels = Entity::select('entities.name')->where('company_id', Auth::user()->company_id)->where('entity_type_id', 19)->first(); //ENTITIES ALSO CHANGES FOR 3; FOR QUEUE PROCESS
					if ($approval_levels != '') {
						if ($send_approval->status_id == $approval_levels->name) {
							$invoiceItemIds = ServiceInvoiceItem::where('service_invoice_id', $send_approval->id)->pluck('id')->toArray();
							$tvsOneCnDatas = JobCardTvsoneCnHistory::whereIn('service_invoice_item_id', $invoiceItemIds)->count();
							$isTvsOneCn = $tvsOneCnDatas && $tvsOneCnDatas > 0;
							// Doc date validation based on min and max offser from entities table
							$minDate = intval(Entity::where('company_id', Auth::user()->company_id)->where('entity_type_id', 15)->pluck('name')->first());
							// if ($minDate) {
							if ($minDate && !$isTvsOneCn) {
								$minDate = $minDate * -1;
								$startDate = date('d-m-Y', strtotime($minDate . 'days'));
								$endDate = date('d-m-Y');

								$document_date = $send_approval->document_date;

								$minOffSetDate = strtotime($startDate);
								$maxOffSetDate = strtotime($endDate);
								$docOffSetDate = strtotime($document_date);
								if ($minOffSetDate > $docOffSetDate || $maxOffSetDate < $docOffSetDate)
									return response()->json(['success' => false, 'errors' => ['Doc date should be match with minimum ' . $startDate . ' and maximum of ' . $endDate . ' for the invoice ' . $send_approval->number]]);
							}
							// Doc date validation based on min and max offser from entities table
							$r = $approved_status->createPdf($send_approval->id);
							if (!$r['success']) {
								DB::rollBack();
								return response()->json($r);
							}
						}
					} else {
						return response()->json(['success' => false, 'errors' => ['Final CN/DN Status has not mapped.!']]);
					}

					//SMS
					if($send_approval->status_id == 4 && !empty($tvs_one_order->customer->mobile_no)){
						$link = url('storage/app/public/service-invoice-pdf/'.$send_approval->number.'.pdf');
			            $short_url = ShortUrl::createShortLink($link, $maxlength = "5");
						$sms_params = [];
			            $sms_params['mobile_number'] = $tvs_one_order->customer->mobile_no;
			            $sms_params['sms_url'] = config('services.tvsone_sms_url');
			            $sms_params['sms_user'] = config('services.tvsone_sms_user');
			            $sms_params['sms_password'] = config('services.tvsone_sms_password');
			            $sms_params['sms_sender_id'] = config('services.tvsone_sms_sender_id');
			            $sms_params['message'] = 'Thanks for purchasing the TVSONE membership, click the link '.$short_url.' to download the Invoice – TVS';
			            tvsoneSendSMS($sms_params);
					}
				}
				DB::commit();
				// return response()->json(['success' => true, 'message' => 'CN/DN Approved successfully']);
				return response()->json(['success' => true, 'message' => 'Approved successfully']);
			} catch (\Exception $e) {
				DB::rollBack();
				// return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
				return response()->json([
                	'success' => false,
	                'error' => 'Server Error',
	                'errors' => [
	                    'Error : ' . $e->getMessage() . '. Line : ' . $e->getLine() . '. File : ' . $e->getFile(),
	                ],
            	]);
			}
		}
	}

	public function updateIRNStatus($id) {
		// dd($request->all());
		DB::beginTransaction();
		try {

			$service_invoice = ServiceInvoice::find($id);

			if($service_invoice && $service_invoice->status_id == 2){
				// RSA ENCRYPTION
		        // $rsa = new Crypt_RSA;
		        // $public_key = 'MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAxqHazGS4OkY/bDp0oklL+Ser7EpTpxyeMop8kfBlhzc8dzWryuAECwu8i/avzL4f5XG/DdSgMz7EdZCMrcxtmGJlMo2tUqjVlIsUslMG6Cmn46w0u+pSiM9McqIvJgnntKDHg90EIWg1BNnZkJy1NcDrB4O4ea66Y6WGNdb0DxciaYRlToohv8q72YLEII/z7W/7EyDYEaoSlgYs4BUP69LF7SANDZ8ZuTpQQKGF4TJKNhJ+ocmJ8ahb2HTwH3Ol0THF+0gJmaigs8wcpWFOE2K+KxWfyX6bPBpjTzC+wQChCnGQREhaKdzawE/aRVEVnvWc43dhm0janHp29mAAVv+ngYP9tKeFMjVqbr8YuoT2InHWFKhpPN8wsk30YxyDvWkN3mUgj3Q/IUhiDh6fU8GBZ+iIoxiUfrKvC/XzXVsCE2JlGVceuZR8OzwGrxk+dvMnVHyauN1YWnJuUTYTrCw3rgpNOyTWWmlw2z5dDMpoHlY0WmTVh0CrMeQdP33D3LGsa+7JYRyoRBhUTHepxLwk8UiLbu6bGO1sQwstLTTmk+Z9ZSk9EUK03Bkgv0hOmSPKC4MLD5rOM/oaP0LLzZ49jm9yXIrgbEcn7rv82hk8ghqTfChmQV/q+94qijf+rM2XJ7QX6XBES0UvnWnV6bVjSoLuBi9TF1ttLpiT3fkCAwEAAQ=='; //PROVIDE FROM BDO COMPANY

		        // $clientid = config('custom.CLIENT_ID');
		        // $rsa->loadKey($public_key);
		        // $rsa->setEncryptionMode(2);
		        // $client_secret_key = config('custom.CLIENT_SECRET_KEY');
		        // $ClientSecret = $rsa->encrypt($client_secret_key);
		        // $clientsecretencrypted = base64_encode($ClientSecret);

		        // $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		        // $app_secret_key = substr(str_shuffle($characters), 0, 32); // RANDOM KEY GENERATE
		        // // $app_secret_key = 'Rdp5EB5w756dVph0C3jCXY1K6RPC6RCD'; // RANDOM KEY GENERATE
		        // $AppSecret = $rsa->encrypt($app_secret_key);
		        // $appsecretkey = base64_encode($AppSecret);
		        // // dump('appsecretkey ' . $appsecretkey);

		        // $bdo_login_url = config('custom.BDO_LOGIN_URL');

		        // $ch = curl_init($bdo_login_url);
				
		        // // Setup request to send json via POST`
		        // $params = json_encode(array(
		        //     'clientid' => $clientid,
		        //     'clientsecretencrypted' => $clientsecretencrypted,
		        //     'appsecretkey' => $appsecretkey,
		        // ));

		        // // Attach encoded JSON string to the POST fields
		        // curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		        // // Set the content type to application/json
		        // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

		        // // Return response instead of outputting
		        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		        // // Execute the POST request
		        // $server_output = curl_exec($ch);
		        // // dd($server_output);

		        // // Get the POST request header status
		        // $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				// // dd($status);
		        // // If header status is not Created or not OK, return error message
		        // if ($status != 200) {
		        //     return [
		        //         'success' => false,
		        //         'errors' => ["Conection Error in BDO Login!"],
		        //     ];
		        //     $errors[] = 'Conection Error in BDO Login!';
		        //     // DB::commit();
		        //     // return response()->json([
		        //     //  'success' => false,
		        //     //  'error' => 'call to URL $bdo_login_url failed with status $status',
		        //     //  'errors' => ["response " . $server_output . ", curl_error " . curl_error($ch) . ", curl_errno " . curl_errno($ch)],
		        //     // ]);
		        // }

		        // curl_close($ch);

		        // $bdo_login_check = json_decode($server_output);

				// if ($bdo_login_check->status == 0) {
		        //     $api_params['message'] = 'Login Failed!';
		        //     $api_logs[0] = $api_params;
		        //     return [
		        //         'success' => false,
		        //         'errors' => [$bdo_login_check->ErrorMsg],
		        //         'api_logs' => $api_logs,
		        //     ];
		        // }

				$authToken = getBdoAuthToken($service_invoice->company_id);
				$errors = $authToken['errors'];
				$bdo_login_url = $authToken["url"];
				if(!$authToken['success']){
					$errors[] = 'Login Failed!';
					return response()->json(['success' => false, 'errors' => ['Login Failed!']]);
				}
				$clientid = config('custom.CLIENT_ID');

				$app_secret_key = $authToken['result']['app_secret'];
				$expiry = $authToken['result']['expiry_date'];
				$bdo_authtoken = $authToken['result']['bdo_authtoken'];
				$status = $authToken['result']['status'];
				$bdo_sek = $authToken['result']['bdo_secret'];

		        // $expiry = $bdo_login_check->expiry;
		        // $bdo_authtoken = $bdo_login_check->bdo_authtoken;
		        // $status = $bdo_login_check->status;
		        // $bdo_sek = $bdo_login_check->bdo_sek;

		        //DECRYPT WITH APP KEY AND BDO SEK KEY
		        // $decrypt_data_with_bdo_sek = self::decryptAesData($app_secret_key, $bdo_sek);
		        // if (!$decrypt_data_with_bdo_sek) {
		        //     $errors[] = 'Decryption Error!';
		        //     return response()->json(['success' => false, 'errors' => 'Decryption Error!']);
		        // }

				// dd($decrypt_data_with_bdo_sek);

				// $bdo_authtoken = $bdo_login_check->bdo_authtoken;

				// dd($bdo_authtoken);
				$service_invoice->type = 'INV';

				//Get City ID
				$client = new Client();
		   
				// $url = 'https://einvoiceapi.bdo.in/bdoapi/public/irnbydocdetails?doctype='.$service_invoice->type.'&docnum='.$service_invoice->number.'&docdate='.date('d/m/Y', strtotime($service_invoice->document_date));
				// $url = 'https://sandboxeinvoiceapi.bdo.in/bdoapi/public/irnbydocdetails?doctype='.$service_invoice->type.'&docnum='.$service_invoice->number.'&docdate='.date('d/m/Y', strtotime($service_invoice->document_date));
				
				$url = config('custom.BDO_IRN_UPDATE_URL') . $service_invoice->type.'&docnum='.$service_invoice->number.'&docdate='.date('d/m/Y', strtotime($service_invoice->document_date));

				$clientid = config('custom.CLIENT_ID');

				// dd($url);
				$response = $client->request('GET', $url, [
					'headers' => [
						'client_id' => $clientid,
						'bdo_authtoken' => $bdo_authtoken,
						'gstin' => $service_invoice->outlets ? ($service_invoice->outlets->gst_number ? $service_invoice->outlets->gst_number : 'N/A') : 'N/A',
					],
				]);

				$body = $response->getBody();
				$stringBody = (string) $body;
				$result = json_decode($stringBody);
				
				if($result->Status == '1'){
					$irn_decrypt_data = self::decryptAesData($bdo_sek, $result->Data);

					// dd($irn_decrypt_data);
					if (!$irn_decrypt_data) {
						$errors[] = 'IRN Decryption Error!';
						return response()->json(['success' => false, 'error' => 'IRN Decryption Error!']);
					}
					// dump($irn_decrypt_data);
					$final_json_decode = json_decode($irn_decrypt_data);
					// dd($final_json_decode);
					// dd($result,$final_json_decode);

					$IRN_images_des = storage_path('app/public/service-invoice/IRN_images');
					File::makeDirectory($IRN_images_des, $mode = 0777, true, true);

					// $url = QRCode::text($final_json_decode->QRCode)->setSize(4)->setOutfile('storage/app/public/service-invoice/IRN_images/' . $service_invoice->number . '.png')->png();
					$url = QRCode::text($final_json_decode->SignedQRCode)->setSize(4)->setOutfile('storage/app/public/service-invoice/IRN_images/' . $service_invoice->number . '.png')->png();

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
							imagealphablending($bg, true);
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
					// $get_version = json_decode($final_json_decode->Invoice); //DOUBT
					// $get_version = json_decode($get_version->data); //DOUBT

					// $image = '<img src="storage/app/public/service-invoice/IRN_images/' . $final_json_decode->AckNo . '.png" title="IRN QR Image">';
					$service_invoice_save = ServiceInvoice::find($service_invoice->id);
					$service_invoice_save->irn_number = $final_json_decode->Irn;
					$service_invoice_save->qr_image = $service_invoice->number . '.png' . '.jpg';
					$service_invoice_save->ack_no = $final_json_decode->AckNo;
					$service_invoice_save->ack_date = $final_json_decode->AckDt;
					// $service_invoice_save->version = $get_version->Version; //DOUBT
					// $service_invoice_save->irn_request = $json_encoded_data; //DOUBT
					$service_invoice_save->irn_response = $irn_decrypt_data;

					// if (!$r['success']) {
					//     $service_invoice_save->status_id = 2; //APPROVAL 1 PENDING
					//     return [
					//         'success' => false,
					//         'errors' => ['Somthing Went Wrong!'],
					//     ];
					// }

					// if (count($errors) > 0) {
					//     $service_invoice->errors = empty($errors) ? NULL : json_encode($errors);
					//     $service_invoice->status_id = 6; //E-Invoice Fail
					//     $service_invoice->save();
					//     // return;
					// }
					$service_invoice_save->errors = empty($errors) ? null : json_encode($errors);
					
					//SEND TO PDF
					// $service_invoice->version = $get_version->Version; // DOUBT
					$service_invoice_save->round_off_amount = $service_invoice->round_off_amount;
					$service_invoice_save->irn_number = $final_json_decode->Irn;
					$service_invoice_save->ack_no = $final_json_decode->AckNo;
					$service_invoice_save->ack_date = $final_json_decode->AckDt;

					$service_invoice_save->status_id = 4; //$approval_levels->next_status_id;
					// $approval_status->status_id = 3;
					$service_invoice_save->comments = NULL;
					$service_invoice_save->updated_at = date("Y-m-d H:i:s");
					$service_invoice_save->save();

					//----------// ENCRYPTION END //----------//
					// $service_invoice['additional_image_name'] = $additional_image_name; //DOUBT
					// $service_invoice['additional_image_path'] = $additional_image_path; //DOUBT

					//dd($serviceInvoiceItem->field_groups);
					$this->data['service_invoice_pdf'] = $service_invoice_save;
					// dd($this->data['service_invoice_pdf']);

					$tax_list = Tax::where('company_id', 1)->orderBy('id', 'ASC')->get();
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
					//     'success' => true,
					// ];
					$r['api_logs'] = [];

					//ENTRY IN AX_EXPORTS
					$r = $service_invoice->exportToAxapta();
					if (!$r['success']) {
						return $r;
					}
				}
			}
			
			DB::commit();

			dump('Success');
			dump($service_invoice);
			// return response()->json(['success' => true, 'message' => $message]);
		} catch (Exception $e) {
			DB::rollBack();
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}

	public function updateServiceInvoicePDF($id) {
		// dd($request->all());
		DB::beginTransaction();
		try {

			$service_invoice = ServiceInvoice::find($id);
			if($service_invoice){
				$r = $service_invoice->createServiceInvoicePdf();
			}
			
			DB::commit();

			dump('Success');
			dump($service_invoice);
			// return response()->json(['success' => true, 'message' => $message]);
		} catch (Exception $e) {
			DB::rollBack();
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}

	public static function decryptAesData($encryption_key, $data)
    {
        $method = 'aes-256-ecb';

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

        $decrypted = openssl_decrypt(base64_decode($data), $method, $encryption_key, OPENSSL_RAW_DATA, $iv);
        return $decrypted;
    }

    public function updateIRNDetails($id) {
		// dd($request->all());
		DB::beginTransaction();
		try {

			$service_invoice = ServiceInvoice::find($id);

			if($service_invoice && $service_invoice->status_id == 2){
				// RSA ENCRYPTION
		        // $rsa = new Crypt_RSA;
		        // $public_key = 'MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAxqHazGS4OkY/bDp0oklL+Ser7EpTpxyeMop8kfBlhzc8dzWryuAECwu8i/avzL4f5XG/DdSgMz7EdZCMrcxtmGJlMo2tUqjVlIsUslMG6Cmn46w0u+pSiM9McqIvJgnntKDHg90EIWg1BNnZkJy1NcDrB4O4ea66Y6WGNdb0DxciaYRlToohv8q72YLEII/z7W/7EyDYEaoSlgYs4BUP69LF7SANDZ8ZuTpQQKGF4TJKNhJ+ocmJ8ahb2HTwH3Ol0THF+0gJmaigs8wcpWFOE2K+KxWfyX6bPBpjTzC+wQChCnGQREhaKdzawE/aRVEVnvWc43dhm0janHp29mAAVv+ngYP9tKeFMjVqbr8YuoT2InHWFKhpPN8wsk30YxyDvWkN3mUgj3Q/IUhiDh6fU8GBZ+iIoxiUfrKvC/XzXVsCE2JlGVceuZR8OzwGrxk+dvMnVHyauN1YWnJuUTYTrCw3rgpNOyTWWmlw2z5dDMpoHlY0WmTVh0CrMeQdP33D3LGsa+7JYRyoRBhUTHepxLwk8UiLbu6bGO1sQwstLTTmk+Z9ZSk9EUK03Bkgv0hOmSPKC4MLD5rOM/oaP0LLzZ49jm9yXIrgbEcn7rv82hk8ghqTfChmQV/q+94qijf+rM2XJ7QX6XBES0UvnWnV6bVjSoLuBi9TF1ttLpiT3fkCAwEAAQ=='; //PROVIDE FROM BDO COMPANY

		        // $clientid = config('custom.CLIENT_ID');
		        // $rsa->loadKey($public_key);
		        // $rsa->setEncryptionMode(2);
		        // $client_secret_key = config('custom.CLIENT_SECRET_KEY');
		        // $ClientSecret = $rsa->encrypt($client_secret_key);
		        // $clientsecretencrypted = base64_encode($ClientSecret);

		        // $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		        // $app_secret_key = substr(str_shuffle($characters), 0, 32); // RANDOM KEY GENERATE
		        // // $app_secret_key = 'Rdp5EB5w756dVph0C3jCXY1K6RPC6RCD'; // RANDOM KEY GENERATE
		        // $AppSecret = $rsa->encrypt($app_secret_key);
		        // $appsecretkey = base64_encode($AppSecret);
		        // // dump('appsecretkey ' . $appsecretkey);

		        // $bdo_login_url = config('custom.BDO_LOGIN_URL');

		        // $ch = curl_init($bdo_login_url);
				
		        // // Setup request to send json via POST`
		        // $params = json_encode(array(
		        //     'clientid' => $clientid,
		        //     'clientsecretencrypted' => $clientsecretencrypted,
		        //     'appsecretkey' => $appsecretkey,
		        // ));

		        // // Attach encoded JSON string to the POST fields
		        // curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

		        // // Set the content type to application/json
		        // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

		        // // Return response instead of outputting
		        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		        // // Execute the POST request
		        // $server_output = curl_exec($ch);
		        // // dd($server_output);

		        // // Get the POST request header status
		        // $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				// // dd($status);
		        // // If header status is not Created or not OK, return error message
		        // if ($status != 200) {
		        //     return [
		        //         'success' => false,
		        //         'errors' => ["Conection Error in BDO Login!"],
		        //     ];
		        //     $errors[] = 'Conection Error in BDO Login!';
		        //     // DB::commit();
		        //     // return response()->json([
		        //     //  'success' => false,
		        //     //  'error' => 'call to URL $bdo_login_url failed with status $status',
		        //     //  'errors' => ["response " . $server_output . ", curl_error " . curl_error($ch) . ", curl_errno " . curl_errno($ch)],
		        //     // ]);
		        // }

		        // curl_close($ch);

		        // $bdo_login_check = json_decode($server_output);

				// if ($bdo_login_check->status == 0) {
		        //     $api_params['message'] = 'Login Failed!';
		        //     $api_logs[0] = $api_params;
		        //     return [
		        //         'success' => false,
		        //         'errors' => [$bdo_login_check->ErrorMsg],
		        //         'api_logs' => $api_logs,
		        //     ];
		        // }

				$authToken = getBdoAuthToken($service_invoice->company_id);
				$errors = $authToken['errors'];
				$bdo_login_url = $authToken["url"];
				if(!$authToken['success']){
					$errors[] = 'Login Failed!';
					return response()->json(['success' => false, 'errors' => ['Login Failed!']]);
				}
				$clientid = config('custom.CLIENT_ID');

				$app_secret_key = $authToken['result']['app_secret'];
				$expiry = $authToken['result']['expiry_date'];
				$bdo_authtoken = $authToken['result']['bdo_authtoken'];
				$status = $authToken['result']['status'];
				$bdo_sek = $authToken['result']['bdo_secret'];

		        // $expiry = $bdo_login_check->expiry;
		        // $bdo_authtoken = $bdo_login_check->bdo_authtoken;
		        // $status = $bdo_login_check->status;
		        // $bdo_sek = $bdo_login_check->bdo_sek;

		        //DECRYPT WITH APP KEY AND BDO SEK KEY
		        // $decrypt_data_with_bdo_sek = self::decryptAesData($app_secret_key, $bdo_sek);
		        // if (!$decrypt_data_with_bdo_sek) {
		        //     $errors[] = 'Decryption Error!';
		        //     return response()->json(['success' => false, 'errors' => 'Decryption Error!']);
		        // }

				// dd($decrypt_data_with_bdo_sek);

				// $bdo_authtoken = $bdo_login_check->bdo_authtoken;

				// dd($bdo_authtoken);
				$service_invoice->type = 'INV';

				//Get City ID
				$client = new Client();
		   
				// $url = 'https://einvoiceapi.bdo.in/bdoapi/public/irnbydocdetails?doctype='.$service_invoice->type.'&docnum='.$service_invoice->number.'&docdate='.date('d/m/Y', strtotime($service_invoice->document_date));
				// $url = 'https://sandboxeinvoiceapi.bdo.in/bdoapi/public/irnbydocdetails?doctype='.$service_invoice->type.'&docnum='.$service_invoice->number.'&docdate='.date('d/m/Y', strtotime($service_invoice->document_date));
				
				$url = config('custom.BDO_IRN_UPDATE_URL') . $service_invoice->type.'&docnum='.$service_invoice->number.'&docdate='.date('d/m/Y', strtotime($service_invoice->document_date));

				$clientid = config('custom.CLIENT_ID');

				// dd($url);
				$response = $client->request('GET', $url, [
					'headers' => [
						'client_id' => $clientid,
						'bdo_authtoken' => $bdo_authtoken,
						'gstin' => $service_invoice->outlets ? ($service_invoice->outlets->gst_number ? $service_invoice->outlets->gst_number : 'N/A') : 'N/A',
					],
				]);

				$body = $response->getBody();
				$stringBody = (string) $body;
				$result = json_decode($stringBody);
				
				if($result->Status == '1'){
					$irn_decrypt_data = self::decryptAesData($bdo_sek, $result->Data);

					// dd($irn_decrypt_data);
					if (!$irn_decrypt_data) {
						$errors[] = 'IRN Decryption Error!';
						return response()->json(['success' => false, 'error' => 'IRN Decryption Error!']);
					}
					// dump($irn_decrypt_data);
					$final_json_decode = json_decode($irn_decrypt_data);
					// dd($final_json_decode);
					// dd($result,$final_json_decode);

					$IRN_images_des = storage_path('app/public/service-invoice/IRN_images');
					File::makeDirectory($IRN_images_des, $mode = 0777, true, true);

					// $url = QRCode::text($final_json_decode->QRCode)->setSize(4)->setOutfile('storage/app/public/service-invoice/IRN_images/' . $service_invoice->number . '.png')->png();
					$url = QRCode::text($final_json_decode->SignedQRCode)->setSize(4)->setOutfile('storage/app/public/service-invoice/IRN_images/' . $service_invoice->number . '.png')->png();

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
							imagealphablending($bg, true);
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
					// $get_version = json_decode($final_json_decode->Invoice); //DOUBT
					// $get_version = json_decode($get_version->data); //DOUBT

					// $image = '<img src="storage/app/public/service-invoice/IRN_images/' . $final_json_decode->AckNo . '.png" title="IRN QR Image">';
					$service_invoice_save = ServiceInvoice::find($service_invoice->id);
					$service_invoice_save->irn_number = $final_json_decode->Irn;
					$service_invoice_save->qr_image = $service_invoice->number . '.png' . '.jpg';
					$service_invoice_save->ack_no = $final_json_decode->AckNo;
					$service_invoice_save->ack_date = $final_json_decode->AckDt;
					// $service_invoice_save->version = $get_version->Version; //DOUBT
					// $service_invoice_save->irn_request = $json_encoded_data; //DOUBT
					$service_invoice_save->irn_response = $irn_decrypt_data;

					// if (!$r['success']) {
					//     $service_invoice_save->status_id = 2; //APPROVAL 1 PENDING
					//     return [
					//         'success' => false,
					//         'errors' => ['Somthing Went Wrong!'],
					//     ];
					// }

					// if (count($errors) > 0) {
					//     $service_invoice->errors = empty($errors) ? NULL : json_encode($errors);
					//     $service_invoice->status_id = 6; //E-Invoice Fail
					//     $service_invoice->save();
					//     // return;
					// }
					$service_invoice_save->errors = empty($errors) ? null : json_encode($errors);
					
					//SEND TO PDF
					// $service_invoice->version = $get_version->Version; // DOUBT
					$service_invoice_save->round_off_amount = $service_invoice->round_off_amount;
					$service_invoice_save->irn_number = $final_json_decode->Irn;
					$service_invoice_save->ack_no = $final_json_decode->AckNo;
					$service_invoice_save->ack_date = $final_json_decode->AckDt;

					$service_invoice_save->status_id = 4; //$approval_levels->next_status_id;
					// $approval_status->status_id = 3;
					$service_invoice_save->comments = NULL;
					$service_invoice_save->updated_at = date("Y-m-d H:i:s");
					$service_invoice_save->save();

					//----------// ENCRYPTION END //----------//
					// $service_invoice['additional_image_name'] = $additional_image_name; //DOUBT
					// $service_invoice['additional_image_path'] = $additional_image_path; //DOUBT

					//dd($serviceInvoiceItem->field_groups);
					$this->data['service_invoice_pdf'] = $service_invoice_save;
					// dd($this->data['service_invoice_pdf']);

					$tax_list = Tax::where('company_id', 1)->orderBy('id', 'ASC')->get();
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
					//     'success' => true,
					// ];
					$r['api_logs'] = [];

					//ENTRY IN AX_EXPORTS
					// $r = $service_invoice->exportToAxapta();
					// if (!$r['success']) {
						// return $r;
					// }
				}
			}
			
			DB::commit();

			dump('Success');
			dump($service_invoice);
			// return response()->json(['success' => true, 'message' => $message]);
		} catch (Exception $e) {
			DB::rollBack();
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}
}
