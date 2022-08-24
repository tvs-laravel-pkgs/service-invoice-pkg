<?php
namespace Abs\ServiceInvoicePkg;

use Abs\ApprovalPkg\ApprovalLevel;
use Abs\ApprovalPkg\ApprovalTypeStatus;
use Abs\AttributePkg\Models\Field;
use Abs\AttributePkg\Models\FieldConfigSource;
use Abs\AttributePkg\Models\FieldGroup;
use Abs\AttributePkg\Models\FieldSourceTable;
use Abs\AxaptaExportPkg\HondaAxaptaExport;
use Abs\SerialNumberPkg\SerialNumberGroup;
use Abs\ServiceInvoicePkg\HondaServiceInvoice;
use Abs\ServiceInvoicePkg\HondaServiceInvoiceItem;
use Abs\ServiceInvoicePkg\ServiceItem;
use Abs\ServiceInvoicePkg\ServiceItemCategory;
use Abs\TaxPkg\Tax;
use App\Address;
use App\ApiLog;
use App\Attachment;
use App\BdoAuthToken;
use App\EInvoiceConfig;
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

class HondaServiceInvoiceController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->soapWrapper = new SoapWrapper;
    }

    public function getServiceInvoiceFilter()
    {
        $this->data['extras'] = [
            'sbu_list' => [],
            'category_list' => collect(ServiceItemCategory::select('id', 'name')->where('type', 'honda')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
            'sub_category_list' => [],
            'cn_dn_statuses' => collect(ApprovalTypeStatus::select('id', 'status')->where('approval_type_id', 1)->orderBy('id', 'asc')->get())->prepend(['id' => '', 'status' => 'Select CN/DN Status']),
            'type_list' => collect(Config::select('id', 'name')->where('config_type_id', 84)->get())->prepend(['id' => '', 'name' => 'Select Service Invoice Type']),
        ];
        return response()->json($this->data);
    }

    public function getServiceInvoiceList(Request $request)
    {
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
        $service_invoice_list = HondaServiceInvoice::
            //withTrashed()
            select(
            'honda_service_invoices.id',
            'honda_service_invoices.number',
            'honda_service_invoices.document_date',
            'honda_service_invoices.total as invoice_amount',
            'honda_service_invoices.is_cn_created',
            'honda_service_invoices.status_id',
            'honda_service_invoices.ack_date',
            'outlets.code as branch',
            'sbus.name as sbu',
            'service_item_categories.name as category',
            'service_item_sub_categories.name as sub_category',
            'addresses.gst_number',
            // DB::raw('IF(service_invoices.to_account_type_id=1440,customers.code,vendors.code) as customer_code'),
            // DB::raw('IF(service_invoices.to_account_type_id=1440,customers.name,vendors.name) as customer_name'),
            'customers.pdf_format_id',
            DB::raw('CASE
                    WHEN honda_service_invoices.to_account_type_id = "1440" THEN customers.code
                    WHEN honda_service_invoices.to_account_type_id = "1441" THEN vendors.code
                    ELSE customers.code END AS customer_code'),
            DB::raw('CASE
                    WHEN honda_service_invoices.to_account_type_id = "1440" THEN customers.name
                    WHEN honda_service_invoices.to_account_type_id = "1441" THEN vendors.name
                    ELSE customers.name END AS customer_name'),
            // 'customers.code as customer_code',
            // 'customers.name as customer_name',
            'configs.name as type_name',
            'configs.id as si_type_id',
            DB::raw('IF(to_account_type.name IS NULL,"Customer",to_account_type.name) as to_account_type'),
            'approval_type_statuses.status',
            'honda_service_invoices.created_by_id'
        )
            ->join('outlets', 'outlets.id', 'honda_service_invoices.branch_id')
            ->join('sbus', 'sbus.id', 'honda_service_invoices.sbu_id')
            ->leftJoin('service_item_sub_categories', 'service_item_sub_categories.id', 'honda_service_invoices.sub_category_id')
            ->leftJoin('service_item_categories', 'service_item_categories.id', 'honda_service_invoices.category_id')
            ->leftJoin('customers', function ($join) {
                $join->on('customers.id', 'honda_service_invoices.customer_id');
            })
            ->leftJoin('vendors', function ($join) {
                $join->on('vendors.id', 'honda_service_invoices.customer_id');
            })
            ->leftJoin('addresses', function ($join) {
                $join->on('addresses.id', 'honda_service_invoices.address_id');
            })
            ->join('configs', 'configs.id', 'honda_service_invoices.type_id')
            ->leftJoin('configs as to_account_type', 'to_account_type.id', 'honda_service_invoices.to_account_type_id')
            ->join('approval_type_statuses', 'approval_type_statuses.id', 'honda_service_invoices.status_id')
        // ->where('honda_service_invoices.company_id', Auth::user()->company_id)
            ->whereIn('approval_type_statuses.approval_type_id', [1, 3, 5])
            ->where(function ($query) use ($first_date_this_month, $last_date_this_month) {
                if (!empty($first_date_this_month) && !empty($last_date_this_month)) {
                    $query->whereRaw("DATE(honda_service_invoices.document_date) BETWEEN '" . $first_date_this_month . "' AND '" . $last_date_this_month . "'");
                }
            })
            ->where(function ($query) use ($invoice_number_filter) {
                if ($invoice_number_filter != null) {
                    $query->where('honda_service_invoices.number', 'like', "%" . $invoice_number_filter . "%");
                }
            })
            ->where(function ($query) use ($request) {
                if (!empty($request->type_id)) {
                    $query->where('honda_service_invoices.type_id', $request->type_id);
                }
            })
            ->where(function ($query) use ($request) {
                if (!empty($request->branch_id)) {
                    $query->where('honda_service_invoices.branch_id', $request->branch_id);
                }
            })
            ->where(function ($query) use ($request) {
                if (!empty($request->sbu_id)) {
                    $query->where('honda_service_invoices.sbu_id', $request->sbu_id);
                }
            })
            ->where(function ($query) use ($request) {
                if (!empty($request->category_id)) {
                    // $query->where('service_item_sub_categories.category_id', $request->category_id);
                    $query->where('honda_service_invoices.category_id', $request->category_id);
                }
            })
        // ->where(function ($query) use ($request) {
        //     if (!empty($request->sub_category_id)) {
        //         $query->where('honda_service_invoices.sub_category_id', $request->sub_category_id);
        //     }
        // })
            ->where(function ($query) use ($request) {
                if (!empty($request->customer_id)) {
                    $query->where('honda_service_invoices.customer_id', $request->customer_id);
                }
            })
            ->where(function ($query) use ($request) {
                if (!empty($request->status_id)) {
                    $query->where('honda_service_invoices.status_id', $request->status_id);
                }
            })
            ->groupBy('honda_service_invoices.id')
            ->orderBy('honda_service_invoices.id', 'Desc');
        // ->get();
        // dd($service_invoice_list);
        if (Entrust::can('view-all-cn-dn')) {
            $service_invoice_list = $service_invoice_list->where('honda_service_invoices.company_id', Auth::user()->company_id);
        } elseif (Entrust::can('view-own-cn-dn')) {
            $service_invoice_list = $service_invoice_list->where('honda_service_invoices.created_by_id', Auth::user()->id);
        } elseif (Entrust::can('view-outlet-based-cn-dn')) {
            $view_user_outlets_only = User::leftJoin('employees', 'employees.id', 'users.entity_id')
                ->leftJoin('employee_outlet', 'employee_outlet.employee_id', 'employees.id')
                ->leftJoin('outlets', 'outlets.id', 'employee_outlet.outlet_id')
                ->where('employee_outlet.employee_id', Auth::user()->entity_id)
                ->where('users.company_id', Auth::user()->company_id)
                ->where('users.user_type_id', 1)
                ->pluck('employee_outlet.outlet_id')
                ->toArray();
            $service_invoice_list = $service_invoice_list->whereIn('honda_service_invoices.branch_id', $view_user_outlets_only);
        } elseif (Entrust::can('view-sbu-based-cn-dn')) {
            $view_employee_sbu_only = Employee::leftJoin('users', 'users.entity_id', 'employees.id')
                ->leftJoin('employee_sbu', 'employee_sbu.employee_id', 'employees.id')
                ->where('employee_sbu.employee_id', Auth::user()->entity_id)
                ->where('employees.company_id', Auth::user()->company_id)
                ->where('users.user_type_id', 1)
                ->pluck('employee_sbu.sbu_id')
                ->toArray();
            $service_invoice_list = $service_invoice_list->whereIn('honda_service_invoices.sbu_id', $view_employee_sbu_only);
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
                $path = URL::to('/storage/app/public/honda-service-invoice-pdf');
                $output = '';
                if ($service_invoice_list->status_id == '4') {
                    $output .= '<a href="#!/service-invoice-pkg/honda-service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>
	                    	<a href="' . $path . '/' . $service_invoice_list->number . '.pdf" class="" target="_blank"><img class="img-responsive" src="' . $img_download . '" alt="Download" title="PDF" />
	                        </a>';
                    if ($service_invoice_list->pdf_format_id == 11311) {
                        //CHOLA CUSTOMER
                        $output .= '<a href="javascript:;" onclick="angular.element(this).scope().cholaPdfDownload(' . $service_invoice_list->id . ')"><img class="img-responsive" src="' . $img_download . '" alt="Download" title="Chola PDF"/>
	                        </a>';
                    }
                    if (Entrust::can('service-invoice-irn-cancel')) {
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
                    }
                    if (Entrust::can('service-invoice-cancel')) {
                        $btype = ($service_invoice_list->gst_number && !empty($service_invoice_list->gst_number))?"B2B":"B2C";
                        if (empty($service_invoice_list->ack_date)) {
                            $output .= '<a href="javascript:;" data-toggle="modal" data-target="#delete_irn"
									onclick="angular.element(this).scope().deleteB2C(' . $service_invoice_list->id . ')" dusk = "delete-btn" title="Cancel '.$btype.'">
									<img src="' . $img_delete . '" alt="Cancel '.$btype.'" class="img-responsive">
									</a>';
                        }
                    }
                } elseif ($service_invoice_list->status_id != '4' && $service_invoice_list->status_id != '3' && $service_invoice_list->status_id != '7' && $service_invoice_list->status_id != '8') {
                    $output .= '<a href="#!/service-invoice-pkg/honda-service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>
	                    	<a href="#!/service-invoice-pkg/honda-service-invoice/edit/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_edit . '" alt="Edit" />
	                    	</a>';
                } elseif ($service_invoice_list->status_id == '3') {
                    $output .= '<a href="#!/service-invoice-pkg/honda-service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>';
                } elseif ($service_invoice_list->status_id == '7') {
                    $output .= '<a href="#!/service-invoice-pkg/honda-service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>
	                    	<a href="' . $path . '/' . $service_invoice_list->number . '.pdf" class="" target="_blank"><img class="img-responsive" src="' . $img_download . '" alt="Download" title="PDF"/>
	                        </a>';
                    if ($service_invoice_list->pdf_format_id == 11311) {
                        //CHOLA CUSTOMER
                        $output .= '<a href="javascript:;" onclick="angular.element(this).scope().cholaPdfDownload(' . $service_invoice_list->id . ')"><img class="img-responsive" src="' . $img_download . '" alt="Download" title="Chola PDF"/>
	                        </a>';
                    }
                    if (Entrust::can('service-invoice-irn-cancel')) {

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

                } elseif ($service_invoice_list->status_id == '8') {
                    $output .= '<a href="#!/service-invoice-pkg/honda-service-invoice/view/' . $type_id . '/' . $service_invoice_list->id . '" class="">
	                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
	                    	</a>
	                    	<a href="' . $path . '/' . $service_invoice_list->number . '.pdf" class="" target="_blank"><img class="img-responsive" src="' . $img_download . '" alt="Download" title="PDF"/>
	                        </a>';
                    if ($service_invoice_list->pdf_format_id == 11311) {
                        //CHOLA CUSTOMER
                        $output .= '<a href="javascript:;" onclick="angular.element(this).scope().cholaPdfDownload(' . $service_invoice_list->id . ')"><img class="img-responsive" src="' . $img_download . '" alt="Download" title="Chola PDF"/>
	                        </a>';
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

    public function getFormData($type_id = null, $id = null)
    {
        if (!$id) {
            $service_invoice = new HondaServiceInvoice;
            // $service_invoice->invoice_date = date('d-m-Y');
            $this->data['action'] = 'Add';
            Session::put('sac_code_value', 'new');
        } else {
            $service_invoice = HondaServiceInvoice::with([
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
            'tax_list' => Tax::select('name', 'id')->where('company_id', 1)->orderBy('id', 'ASC')->get(),
            'category_list' => collect(ServiceItemCategory::select('name', 'id')->where('type', 'honda')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
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
        $this->data['tcs_limit'] = Entity::where('entity_type_id', 38)->where('company_id', Auth::user()->company_id)->pluck('name')->first();
        $this->data['success'] = true;
        return response()->json($this->data);
    }

    // public function getServiceItemSubCategories($service_item_category_id) {
    //     return ServiceItemSubCategory::getServiceItemSubCategories($service_item_category_id);
    // }

    public function getSbus($outlet_id)
    {
        return Sbu::getSbus($outlet_id);
    }

    // public function searchCustomer(Request $r) {
    //     return Customer::searchCustomer($r);
    // }

    public function searchField(Request $r)
    {
        return Field::searchField($r);
    }

    // public function getCustomerDetails(Request $request) {
    //     return Customer::getDetails($request);
    // }

    public function searchBranch(Request $r)
    {
        $key = $r->key;
        $list = Outlet::with(['primaryAddress'])->select(
            'outlets.id',
            'outlets.code',
            'outlets.name',
            'outlets.lsd'
        );
        if (!Entrust::can('create-service-invoice-for-all-outlets')) {
            $list = $list->leftJoin('employee_outlet', 'employee_outlet.outlet_id', 'outlets.id')
                ->leftJoin('employees', 'employees.id', 'employee_outlet.employee_id')
                ->leftJoin('users', 'users.entity_id', 'employees.id')
                ->where('users.id', Auth::user()->id);
        } else {
            $list = $list->where('outlets.company_id', Auth::user()->company_id);
        }
        $list = $list->whereNotNull('outlets.lsd')
                    ->where(function ($q) use ($key) {
                        $q->where('outlets.name', 'like', '%' . $key . '%')
                            ->orWhere('outlets.code', 'like', '%' . $key . '%')
                        ;
                    })
            ->get();
        return response()->json($list);
    }

    public function getBranchDetails(Request $request)
    {
        return Outlet::getDetails($request);
    }

    public function searchServiceItem(Request $r)
    {
        return ServiceItem::searchServiceItem($r);
    }

    public function getServiceItemDetails(Request $request)
    {
        //dd($request->all());

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

    public function getServiceItem(Request $request)
    {
        //dd($request->all());
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
        
        $KFC_tax_amount = 0;
        
        if ($request->type_id != 1060) {
            //NOT ABLE TO CALCULATE THE KFC FOR CN
            if ($request->state_id) {
                // dd('in');
                if (($request->state_id == 3) && ($outlet->state_id == 3)) {
                    //3 FOR KERALA
                    //check customer state and outlet states are equal KL.  //add KFC tax
                    if (!$request->gst_number) {
                        //customer dont't have GST
                        if (!is_null($service_item->sac_code_id)) {

                            $document_date = (string) $request->hid_document_date;
                            $date1 = Carbon::createFromFormat('d-m-Y', '31-07-2021');
                            $date2 = Carbon::createFromFormat('d-m-Y', $document_date);
                            $result = $date1->gte($date2);

							$percentage = 0;
                            if ($result) {
                                //customer have HSN and SAC Code
                                $gst_total += round((1 / 100) * ($request->qty * $request->amount), 2);
                                $KFC_tax_amount = round($request->qty * $request->amount * 1 / 100, 2); //ONE PERCENTAGE FOR KFC
								$percentage = 1;
                            }

                            $service_item['KFC'] = [ //4 for KFC
                                'percentage' => $percentage,
                                'amount' => $KFC_tax_amount,
                            ];
                        }
                    }
                }
            }
        }
        // dump($gst_total);
        //FOR TCS TAX CALCULATION
        $TCS_tax_amount = 0;
        $tcs_total = 0;
 
        //FOR CESS on GST TAX CALCULATION
        $cess_gst_tax_amount = 0;
        $cess_gst_total = 0;
        if ($service_item) {
            if ($service_item->cess_on_gst_percentage) {
                $cess_gst_total = round(($request->qty * $request->amount) * $service_item->cess_on_gst_percentage / 100, 2);
                $cess_gst_tax_amount = round(($request->qty * $request->amount) * $service_item->cess_on_gst_percentage / 100, 2); //PERCENTAGE FOR CESS on GST
                $service_item['CESS'] = [ // for CESS on GST
                    'percentage' => $service_item->cess_on_gst_percentage,
                    'amount' => $cess_gst_tax_amount,
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
        $service_item->total = round($request->qty * $request->amount, 2) + $gst_total + $tcs_total + $cess_gst_total;

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

    public function saveHondaServiceInvoice(Request $request)
    {
         DB::beginTransaction();
        try {

            $error_messages = [
                'branch_id.required' => 'Branch is required',
                'sbu_id.required' => 'Sbu is required',
                'category_id.required' => 'Category is required',
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
                'address_id' => [
                    'required:true',
                ],
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
                    // ->where('company_id', Auth::user()->company_id)
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
                if ($request->category_id == 4 || $request->category_id == 11) {
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
                    $generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, null, null, null);
                } else {
                    //STATE BUSINESS BASED CODE
                    $generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, null, null, $sbu->business_id);
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
                        'unique:honda_service_invoices,number,' . $request->id . ',id,company_id,' . Auth::user()->company_id,
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
                $service_invoice = HondaServiceInvoice::find($request->id);
                $service_invoice->updated_at = date("Y-m-d H:i:s");
                $service_invoice->updated_by_id = Auth()->user()->id;
                $message = 'Service invoice was successfully updated and sent for approval.';
            } else {
                $service_invoice = new HondaServiceInvoice();
                $service_invoice->created_at = date("Y-m-d H:i:s");
                $service_invoice->created_by_id = Auth()->user()->id;
                $service_invoice->number = isset($generateNumber['number']) ?  $generateNumber['number']:'';
                if ($approval_status != '') {
                    $service_invoice->status_id = 1; //$approval_status->name;
                } else {
                    return response()->json(['success' => false, 'errors' => ['Initial CN/DN Status has not mapped.!']]);
                }
                $message = 'Service invoice was successfully initiated and sent for approval.';
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
                HondaServiceInvoiceItem::whereIn('id', $service_invoice_item_removal_ids)->delete();
            }

            //SAVE SERVICE INVOICE ITEMS
            if ($request->service_invoice_items) {
                if (!empty($request->service_invoice_items)) {
                    //VALIDATE UNIQUE
                    $service_invoice_items = collect($request->service_invoice_items)->pluck('service_item_id')->toArray();
                    // $service_invoice_items_unique = array_unique($service_invoice_items);
                    // if (count($service_invoice_items) != count($service_invoice_items_unique)) {
                    //     return response()->json(['success' => false, 'errors' => ['Service invoice items has already been taken']]);
                    // }
                    foreach ($request->service_invoice_items as $key => $val) {
                        $service_invoice_item = HondaServiceInvoiceItem::firstOrNew([
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
                                $service_invoice_item->taxes()->attach($tax_val['tax_id'], ['percentage' => isset($tax_val['percentage']) ? $tax_val['percentage'] : 0, 'amount' => isset($tax_val['amount']) ? $tax_val['amount'] : 0]);
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
            $attachement_path = storage_path('app/public/honda-service-invoice/attachments/');
            Storage::makeDirectory($attachement_path, 0777);
            if (!empty($request->proposal_attachments)) {
                foreach ($request->proposal_attachments as $key => $proposal_attachment) {
                    $value = rand(1, 100);
                    $image = $proposal_attachment;
                    $extension = $image->getClientOriginalExtension();
                    $name = $service_invoice->id . 'honda_service_invoice_attachment' . $value . '.' . $extension;
                    $proposal_attachment->move(storage_path('app/public/honda-service-invoice/attachments/'), $name);
                    $attachement = new Attachment;
                    $attachement->attachment_of_id = 130173;
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

    public function createPdf($service_invoice_id)
    {
        // try {
        // dd($service_invoice_id);
        $errors = [];

        $service_invoice = $service_invoice_pdf = HondaServiceInvoice::with([
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
            'serviceInvoiceItems.serviceItem.subCategory',
            'serviceInvoiceItems.serviceItem.subCategory.attachment',
            'serviceInvoiceItems.taxes',
        ])->find($service_invoice_id);

       
        $service_invoice->customer;
        $service_invoice->address;
        $service_invoice->company->formatted_address = $service_invoice->company->primaryAddress ? $service_invoice->company->primaryAddress->getFormattedAddress() : 'NA';
     
        $service_invoice->customer->formatted_address = $service_invoice->address ? $service_invoice->address->address_line1 : 'NA';

        if ($service_invoice->to_account_type_id == 1440) {

            $state = State::find($service_invoice->address ? $service_invoice->address->state_id : null);
            $service_invoice->address->state_code = $state ? $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-' : '-';
          
        } else {
            $state = State::find($service_invoice->address ? $service_invoice->address->state_id : null);
           
            $service_invoice->address->state_code = $state ? $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-' : '-';

        }
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
            $additional_image_name = '';
            $additional_image_path = '';
            foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                // dd($serviceInvoiceItem->serviceItem->subCategory);
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

                if (isset($serviceInvoiceItem->serviceItem->subCategory->attachment) && $serviceInvoiceItem->serviceItem->subCategory->attachment) {
                    $additional_image_name = $serviceInvoiceItem->serviceItem->subCategory->attachment->name;
                    $additional_image_path = base_path('storage/app/public/honda-service-invoice/service-item-sub-category/attachments/');
                }
            }
        }
        // dd($item_count, $item_count_with_tax_code);
        //dd($service_invoice->type_id);
        $type = $serviceInvoiceItem->serviceItem;
        $circular_detail = '';
        if (!empty($type->sac_code_id) && ($service_invoice->type_id == 1060)) {
            $service_invoice->sac_code_status = 'CREDIT NOTE(CRN)';
            $service_invoice->document_type = 'CRN';
        } elseif (empty($type->sac_code_id) && ($service_invoice->type_id == 1060)) {
            $service_invoice->sac_code_status = 'FINANCIAL CREDIT NOTE';
            $service_invoice->document_type = 'CRN';
            $circular_detail = '[As per circular No 92/11/2019 dated 07/03/2019]';
        } elseif ($service_invoice->type_id == 1061) {
            $service_invoice->sac_code_status = 'Tax Invoice(DBN)';
            $service_invoice->document_type = 'DBN';
        } else {
            $service_invoice->sac_code_status = 'Invoice(INV)';
            $service_invoice->document_type = 'INV';
        }

        $eInvoiceConfigId = config("service-invoice-pkg.eInvoiceConfigIdCN");
        if ($service_invoice->type_id == 1060) {
            $service_invoice->type = 'CRN';
            $eInvoiceConfigId = config("service-invoice-pkg.eInvoiceConfigIdCN");
        } elseif ($service_invoice->type_id == 1061) {
            $service_invoice->type = 'DBN';
            $eInvoiceConfigId = config("service-invoice-pkg.eInvoiceConfigIdDN");
        } elseif ($service_invoice->type_id == 1062) {
            $service_invoice->type = 'INV';
            $eInvoiceConfigId = config("service-invoice-pkg.eInvoiceConfigIdINV");
        }

        if ($service_invoice->total > $service_invoice->final_amount) {
            $service_invoice->round_off_amount = number_format(($service_invoice->final_amount - $service_invoice->total), 2);
        } elseif ($service_invoice->total < $service_invoice->final_amount) {
            $service_invoice->round_off_amount;
        } else {
            $service_invoice->round_off_amount = 0;
        }
       

        if (empty($service_invoice->address->state_id)) {
            $errors[] = 'Customer State Required. Customer State Not Found!';
            return [
                'success' => false,
                'errors' => ['Customer State Required. Customer State Not Found!'],
            ];
        }

        $eInvoiceConfig = EInvoiceConfig::where([
            "config_id"=>$eInvoiceConfigId,"status"=>0,"company_id"=>Auth::user()->company_id
        ])->count();
        $fy_start_date = Config::getConfigName(129380);
        $fy_start_date = date('Y-m-d', strtotime($fy_start_date));
        $inv_date = date('Y-m-d', strtotime($service_invoice->document_date));
        if (!$inv_date)
            $inv_date = date('Y-m-d', strtotime($service_invoice->invoice_date));
        if ($fy_start_date > $inv_date && $service_invoice->e_invoice_registration == 1 && $service_invoice->address->gst_number && $item_count == $item_count_with_tax_code) {
            $eInvoiceConfig = 1;
        }

        // print_r("eInvoiceConfigId");
        // print_r($eInvoiceConfigId);

        if (empty($eInvoiceConfig) && $service_invoice->e_invoice_registration == 1) {
            // dd(1);
            //FOR IRN REGISTRATION
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
                    if (strlen(preg_replace('/\r|\n|:|"/', ",", $service_invoice->address->address_line1)) > 100) {
                        $errors[] = 'Customer Address Maximum Allowed Length 100!';
                        return [
                            'success' => false,
                            'errors' => ['Customer Address Maximum Allowed Length 100!'],
                        ];
                        // DB::commit();
                    }
                }

                // $service_invoice->irnCreate($service_invoice_id);
                // BDO Login
                $api_params = [
                    'type_id' => $service_invoice->type_id,
                    'entity_number' => $service_invoice->number,
                    'entity_id' => $service_invoice->id,
                    'user_id' => Auth::user()->id,
                    'created_by_id' => Auth::user()->id,
                ];

                $authToken = getBdoAuthToken(Auth::user()->company_id);
                // dd($authToken);
                $errors = $authToken['errors'];
                $api_params['errors'] = empty($errors) ? null : json_encode($errors);
                $bdo_login_url = $authToken["url"];
                $api_params['url'] = $bdo_login_url;
                $api_params['src_data'] = isset($authToken["params"])?$authToken["params"]:json_encode([]);
                $api_params['response_data'] = isset($authToken["server_output"])?$authToken["server_output"]:json_encode([]);
                if(!$authToken["success"]){
                    $api_params['message'] = 'Login Failed!';
                    $api_params["status_id"] = 11272;
                    $authToken['api_params'] = $api_params;
                    return $authToken;
                }
                $api_params["status_id"] = 11271;
                $api_params['message'] = 'Login Success!';
                $clientid = config('custom.CLIENT_ID');
                $app_secret_key = $authToken["result"]["app_secret"];
                $expiry = $authToken["result"]["expiry_date"];
                $bdo_authtoken = $authToken["result"]["bdo_authtoken"];
                $status = $authToken["result"]["status"];
                //DECRYPTED BDO SEK KEY
                $decrypt_data_with_bdo_sek = $authToken["result"]["bdo_secret"];
                $api_logs[1] = $api_params;

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
                $cess_on_gst_total = 0;
                foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                    $item = [];
                    // dd($serviceInvoiceItem);

                    //GET TAXES
                    $state_id = $service_invoice->address ? $service_invoice->address->state_id ? $service_invoice->address->state_id : '' : '';

                    $taxes = Tax::getTaxes($serviceInvoiceItem->service_item_id, $service_invoice->branch_id, $service_invoice->customer_id, $service_invoice->to_account_type_id, $state_id);
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

                    // //FOR TCS TAX
                    $tcs_amount = DB::table('honda_service_invoice_item_tax')->where('service_invoice_item_id', $serviceInvoiceItem->id)->where('tax_id', 5)->pluck('amount')->first();
                    if ($tcs_amount > 0) {
                        $tcs_total += $tcs_amount;
                    }
                     

                    //FOR CESS on GST TAX
                    if ($service_item->cess_on_gst_percentage) {
                        // $gst_total = 0;
                        // $gst_total = $cgst_amt + $sgst_amt + $igst_amt;
                        // $cess_on_gst_total += round(($gst_total + $serviceInvoiceItem->sub_total) * $service_item->cess_on_gst_percentage / 100, 2);
                        $cess_on_gst_total += round(($serviceInvoiceItem->sub_total) * $service_item->cess_on_gst_percentage / 100, 2);
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
                            'supplydir' => "O", //NULL ADDED 28-sep-2020 discussion "supplydir": "O"
                        ),
                        'DocDtls' => array(
                            "Typ" => $service_invoice->type,
                            "No" => $service_invoice->number,
                            // "No" => '23AUG2020SN166',
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
                            "OthChrg" => number_format($tcs_total + $cess_on_gst_total, 2),
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
               
                //AES ENCRYPT
                //ENCRYPT WITH Decrypted BDO SEK KEY TO PLAIN TEXT AND JSON DATA
                $encrypt_data = self::encryptAesData($decrypt_data_with_bdo_sek, $json_encoded_data);
                if (!$encrypt_data) {
                    $errors[] = 'IRN Encryption Error!';
                    return response()->json(['success' => false, 'error' => 'IRN Encryption Error!']);
                }
                // dd($encrypt_data);

                //ENCRYPTED GIVEN DATA TO DBO
                // $bdo_generate_irn_url = 'https://sandboxeinvoiceapi.bdo.in/bdoapi/public/generateIRN';
                // $bdo_generate_irn_url = 'https://einvoiceapi.bdo.in/bdoapi/public/generateIRN'; //LIVE
                $bdo_generate_irn_url = config('custom.BDO_IRN_REGISTRATION_URL');

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
                // dump($generate_irn_output_data);

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
                    'status_id' => $status == 0 ? 11272 : 11271,
                    // 'errors' => !empty($errors) ? NULL : json_encode($errors),
                    'created_by_id' => Auth::user()->id,
                ];

                if($generate_irn_output_data == "GSP AUTHTOKEN IS NOT VALID"){
                    return [
                        'success' => false,
                        'errors' => "GSP AUTHTOKEN IS NOT VALID, TRY AGAIN",
                        'api_logs' => $api_logs
                    ];
                }

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
                    $api_params['errors'] = empty($errors) ? 'Somthin went worng!, Try again later!' : json_encode($errors);
                    $api_params['message'] = 'Error GENERATE IRN array!';

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
                        $api_params['message'] = 'Error GENERATE IRN!';

                        $api_params['errors'] = empty($errors) ? 'Error GENERATE IRN, Try again later!' : json_encode($errors);
                  
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

                $api_params['errors'] = null;
                $api_logs[4] = $api_params;

                // dump($generate_irn_output['Data']);

                //AES DECRYPTION AFTER GENERATE IRN
                $irn_decrypt_data = self::decryptAesData($decrypt_data_with_bdo_sek, $generate_irn_output['Data']);
                // dd($irn_decrypt_data);
                if (!$irn_decrypt_data) {
                    $errors[] = 'IRN Decryption Error!';
                    return ['success' => false, 'error' => 'IRN Decryption Error!'];
                }
                // dump($irn_decrypt_data);
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

                $IRN_images_des = storage_path('app/public/honda-service-invoice/IRN_images');
                File::makeDirectory($IRN_images_des, $mode = 0777, true, true);

                $qr_code_name = $service_invoice->company_id . $service_invoice->number;
                // $url = QRCode::text($final_json_decode->QRCode)->setSize(4)->setOutfile('storage/app/public/honda-service-invoice/IRN_images/' . $service_invoice->number . '.png')->png();
                $url = QRCode::text($final_json_decode->SignedQRCode)->setSize(4)->setOutfile('storage/app/public/honda-service-invoice/IRN_images/' . $qr_code_name . '.png')->png();

                // $file_name = $service_invoice->number . '.png';

                $qr_attachment_path = base_path("storage/app/public/honda-service-invoice/IRN_images/" . $qr_code_name . '.png');
                // dump($qr_attachment_path);
                if (file_exists($qr_attachment_path)) {
                    $ext = pathinfo(base_path("storage/app/public/honda-service-invoice/IRN_images/" . $qr_code_name . '.png'), PATHINFO_EXTENSION);
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

                        $service_invoice->qr_image = base_path("storage/app/public/honda-service-invoice/IRN_images/" . $qr_code_name . '.png') . '.jpg';
                    }
                } else {
                    $service_invoice->qr_image = '';
                }
                $get_version = json_decode($final_json_decode->Invoice);
                $get_version = json_decode($get_version->data);

                // $image = '<img src="storage/app/public/honda-service-invoice/IRN_images/' . $final_json_decode->AckNo . '.png" title="IRN QR Image">';
                $service_invoice_save = HondaServiceInvoice::find($service_invoice_id);
                $service_invoice_save->irn_number = $final_json_decode->Irn;
                $service_invoice_save->qr_image = $qr_code_name . '.png' . '.jpg';
                $service_invoice_save->ack_no = $final_json_decode->AckNo;
                $service_invoice_save->ack_date = $final_json_decode->AckDt;
                $service_invoice_save->version = $get_version->Version;
                $service_invoice_save->irn_request = $json_encoded_data;
                $service_invoice_save->irn_response = $irn_decrypt_data;
 
                $service_invoice->errors = empty($errors) ? null : json_encode($errors);
                $service_invoice_save->save();

                //SEND TO PDF
                $service_invoice->version = $get_version->Version;
                $service_invoice->round_off_amount = $service_invoice->round_off_amount;
                $service_invoice->irn_number = $final_json_decode->Irn;
                $service_invoice->ack_no = $final_json_decode->AckNo;
                $service_invoice->ack_date = $final_json_decode->AckDt;

                // dd('no error');

            } else {
                // dd('in');
                //QR CODE ONLY FOR B2C CUSTOMER
                $this->qrCodeGeneration($service_invoice);
                // return ServiceInvoice::b2cQrCodeGenerate();
            }
        } else {
            if(empty($eInvoiceConfig))
                $this->qrCodeGeneration($service_invoice);
        } 
        //----------// ENCRYPTION END //----------//
        $service_invoice['additional_image_name'] = $additional_image_name;
        $service_invoice['additional_image_path'] = $additional_image_path;

        //dd($serviceInvoiceItem->field_groups);
        $this->data['service_invoice_pdf'] = $service_invoice;
        $this->data['circular_detail'] = $circular_detail;
        // dd($this->data['service_invoice_pdf']);

        $tax_list = Tax::where('company_id', 1)->orderBy('id', 'ASC')->get();
        $this->data['tax_list'] = $tax_list;
        // dd($this->data['tax_list']);
        $path = storage_path('app/public/honda-service-invoice-pdf/');
        $pathToFile = $path . '/' . $service_invoice->number . '.pdf';
        $name = $service_invoice->number . '.pdf';
        File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

        $pdf = app('dompdf.wrapper');
        $pdf->getDomPDF()->set_option("enable_php", true);
        $pdf = $pdf->loadView('honda-service-invoices/pdf/index', $this->data);

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

        return $r;
        // } catch (Exception $e) {
        //     return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
        // }
    }

    public function viewServiceInvoice($type_id, $id)
    {
        $service_invoice = HondaServiceInvoice::with([
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
        $service_invoice->ack_date = $service_invoice->ack_date ? date("d-m-Y H:i:s", strtotime($service_invoice->ack_date)) : null;

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
            'tax_list' => Tax::select('name', 'id')->where('company_id', 1)->orderBy('id', 'ASC')->get(),
            'category_list' => collect(ServiceItemCategory::select('name', 'id')->where('type', 'honda')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
            'sub_category_list' => [],
            'uom_list' => EInvoiceUom::getList(),
        ];
        $this->data['approval_status'] = ApprovalLevel::find(1);
        $this->data['service_invoice_status'] = ApprovalTypeStatus::join('honda_service_invoices', 'honda_service_invoices.status_id', 'approval_type_statuses.id')->where('honda_service_invoices.company_id', Auth::user()->company_id)->where('honda_service_invoices.id', $id)->first();
        $this->data['tcs_limit'] = Entity::where('entity_type_id', 38)->where('company_id', Auth::user()->company_id)->pluck('name')->first();
        $this->data['action'] = 'View';
        $this->data['success'] = true;
        $this->data['service_invoice'] = $service_invoice;
        return response()->json($this->data);
    }

    public function saveApprovalStatus(Request $request)
    {
        // dd($request->all());
        DB::beginTransaction();
        try {
            $send_approval = HondaServiceInvoice::with(['toAccountType', 'address'])->find($request->id);
            $send_approval->customer;
            if ($send_approval->e_invoice_registration == 1) {
                if ($send_approval->address) {
                    if ($send_approval->address->gst_number) {
                        $customer_trande_name_check = Customer::getGstDetail($send_approval->address ? $send_approval->address->gst_number : null);
                        if ($customer_trande_name_check->original['success'] == false) {
                            return response()->json(['success' => false, 'errors' => [$customer_trande_name_check->original['error']]]);
                        }
                        // dump(trim(strtolower($customer_trande_name_check->original['trade_name'])), trim(strtolower($send_approval->customer->name)));
                        if (trim(strtolower($customer_trande_name_check->original['legal_name'])) != trim(strtolower($send_approval->customer->name))) {
                            // return response()->json(['success' => false, 'errors' => ['Customer Name Not Matched with GSTIN Registration!']]);
                            if (trim(strtolower($customer_trande_name_check->original['trade_name'])) != trim(strtolower($send_approval->customer->name))) {
                                return response()->json(['success' => false, 'errors' => ['Customer Name Not Matched with GSTIN Registration!']]);
                            }
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

    public function sendMultipleApproval(Request $request)
    {
        $send_for_approvals = HondaServiceInvoice::whereIn('id', $request->send_for_approval)->where('status_id', 1)->pluck('id')->toArray();
        $next_status = 2; //ApprovalLevel::where('approval_type_id', 1)->pluck('current_status_id')->first();
        if (count($send_for_approvals) == 0) {
            return response()->json(['success' => false, 'errors' => ['No New CN/DN Status in the list!']]);
        } else {
            DB::beginTransaction();
            try {
                foreach ($send_for_approvals as $key => $value) {
                    // return $this->saveApprovalStatus($value, $next_status);
                    // $send_approval = ServiceInvoice::find($value);
                    $send_approval = HondaServiceInvoice::with(['toAccountType', 'address'])->find($value);
                    $send_approval->customer;
                    if ($send_approval->e_invoice_registration == 1) {
                        if ($send_approval->address) {
                            if ($send_approval->address->gst_number) {
                                $customer_trande_name_check = Customer::getGstDetail($send_approval->address ? $send_approval->address->gst_number : null);
                                if ($customer_trande_name_check->original['success'] == false) {
                                    return response()->json(['success' => false, 'errors' => [$customer_trande_name_check->original['error']]]);
                                }
                                // dump(trim(strtolower($customer_trande_name_check->original['trade_name'])), trim(strtolower($send_approval->customer->name)));
                                if (trim(strtolower($customer_trande_name_check->original['legal_name'])) != trim(strtolower($send_approval->customer->name))) {
                                    // return response()->json(['success' => false, 'errors' => ['Customer Name Not Matched with GSTIN Registration!']]);
                                    if (trim(strtolower($customer_trande_name_check->original['trade_name'])) != trim(strtolower($send_approval->customer->name))) {
                                        return response()->json(['success' => false, 'errors' => ['Customer Name Not Matched with GSTIN Registration!']]);
                                    }
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
                        }
                    } else {
                        $send_approval->status_id = $next_status;
                        $send_approval->updated_by_id = Auth()->user()->id;
                        $send_approval->updated_at = date("Y-m-d H:i:s");
                        $send_approval->save();
                    }
                    //         $send_approval->status_id = $next_status;
                    //         $send_approval->updated_by_id = Auth()->user()->id;
                    //         $send_approval->updated_at = date("Y-m-d H:i:s");
                    //         $send_approval->save();
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

    public function exportServiceInvoicesToExcel(Request $request)
    {
        // dd($request->all());
        // ini_set('memory_limit', '-1');
        ini_set('max_execution_time', 0);

        ob_end_clean();
        $date_range = explode(" to ", $request->invoice_date);

        // $approved_status = ApprovalLevel::where('approval_type_id', 1)->pluck('next_status_id')->first();
        if ($request->export_type == "Export") {
            $query = HondaServiceInvoice::select('honda_service_invoices.*')
            // ->join('service_item_sub_categories as sc', 'sc.id', 'honda_service_invoices.sub_category_id')
                ->where('document_date', '>=', date('Y-m-d', strtotime($date_range[0])))
                ->where('document_date', '<=', date('Y-m-d', strtotime($date_range[1])))
                ->where('honda_service_invoices.company_id', Auth::user()->company_id)
            // ->where('status_id', 4)
                ->whereIn('status_id', [4, 7, 8])
                ->where(function ($query) use ($request) {
                    if ($request->invoice_number) {
                        $query->where('honda_service_invoices.number', 'like', "%" . $request->invoice_number . "%");
                    }
                })
                ->where(function ($query) use ($request) {
                    if (!empty($request->type_id)) {
                        $query->where('honda_service_invoices.type_id', $request->type_id);
                    }
                })
                ->where(function ($query) use ($request) {
                    if (!empty($request->branch_id)) {
                        $query->where('honda_service_invoices.branch_id', $request->branch_id);
                    }
                })
                ->where(function ($query) use ($request) {
                    if (!empty($request->sbu_id)) {
                        $query->where('honda_service_invoices.sbu_id', $request->sbu_id);
                    }
                })
                ->where(function ($query) use ($request) {
                    if (!empty($request->category_id)) {
                        $query->where('honda_service_invoices.category_id', $request->category_id);
                        // $query->where('sc.category_id', $request->category_id);
                    }
                })
            // ->where(function ($query) use ($request) {
            //     if (!empty($request->sub_category_id)) {
            //         $query->where('honda_service_invoices.sub_category_id', $request->sub_category_id);
            //     }
            // })
                ->where(function ($query) use ($request) {
                    if (!empty($request->customer_id)) {
                        $query->where('honda_service_invoices.customer_id', $request->customer_id);
                    }
                })
                ->where(function ($query) use ($request) {
                    if (Entrust::can('view-own-cn-dn')) {
                        $query->where('honda_service_invoices.created_by_id', Auth::id());
                    }
                })
            ;

            $service_invoices = clone $query;
            $service_invoices = $service_invoices->get();

            foreach ($service_invoices as $service_invoice) {
                // dd($service_invoice->status_id);
                if ($service_invoice->status_id != 7 && $service_invoice->status_id != 8) {
                    $service_invoice->exportToAxapta(true);
                }
            }

            $service_invoice_ids = clone $query;

            $service_invoice_ids = $service_invoice_ids->pluck('honda_service_invoices.id');
            // dump($service_invoice_ids);
            $axapta_records = HondaAxaptaExport::where([
                'company_id' => Auth::user()->company_id,
                'entity_type_id' => 1400,
            ])
                ->where(function ($query) use ($request) {
                    if ($request->created_date) {
                        $created_date_range = explode(" to ", $request->created_date);
                        $time1 = '00:00:00';
                        $time2 = '24:00:00';
                        if (!empty($created_date_range)) {
                            $query->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($created_date_range[0] . ' ' . $time1)))
                                ->where('created_at', '<=', date('Y-m-d H:i:s', strtotime($created_date_range[1] . ' ' . $time2)));
                        }
                    }
                })
                ->whereIn('entity_id', $service_invoice_ids)
                ->get()
                ->toArray();
            // dd($axapta_records);

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
                //SARAVANABAVAN SIR TOLD TO REMOVE BELOW COLUMNS IN AXAPTA EXPORT START (22 DEC 2020)
                unset($axapta_record['Due']);
                unset($axapta_record['PaymReference']);
                unset($axapta_record['TVSHSNCode']);
                unset($axapta_record['TVSSACCode']);
                unset($axapta_record['TVSVendorLocationID']);
                unset($axapta_record['TVSCustomerLocationID']);
                unset($axapta_record['TVSCompanyLocationId']);
                //END
                $axapta_record['LineNum'] = $key + 1;
            }

            $file_name = 'cn-dn-export-' . date('Y-m-d-H-i-s');
            Excel::create($file_name, function ($excel) use ($axapta_records) {
                $excel->sheet('cn-dns', function ($sheet) use ($axapta_records) {

                    $sheet->fromArray($axapta_records);
                });
            })->store('xlsx')
            //->download('xlsx')
            ;

        } elseif ($request->export_type == "TCS Export") {
            $query = HondaServiceInvoice::with([
                'company',
                'type',
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
                //     $query->whereNotNull('tcs_percentage');
                // },
                // 'serviceInvoiceItems.eavVarchars',
                // 'serviceInvoiceItems.eavInts',
                // 'serviceInvoiceItems.eavDatetimes',
                // 'serviceInvoiceItems.eInvoiceUom',
                'serviceInvoiceItems.serviceItem.taxCode',
                'serviceInvoiceItems.serviceItem.taxCode.taxes',
                // 'serviceInvoiceItems.taxes',
            ])
                ->where('document_date', '>=', date('Y-m-d', strtotime($date_range[0])))
                ->where('document_date', '<=', date('Y-m-d', strtotime($date_range[1])))
                ->where('honda_service_invoices.company_id', Auth::user()->company_id)
            // ->where('status_id', 4)
                ->whereIn('status_id', [4, 7, 8])
            // ->get()
            ;
            if (Entrust::can('tcs-export-all')) {
                $query = $query->where('honda_service_invoices.company_id', Auth::user()->company_id);
            } elseif (Entrust::can('tcs-export-own')) {
                $query = $query->where('honda_service_invoices.created_by_id', Auth::user()->id);
            } elseif (Entrust::can('tcs-export-outlet-based')) {
                $view_user_outlets_only = User::leftJoin('employees', 'employees.id', 'users.entity_id')
                    ->leftJoin('employee_outlet', 'employee_outlet.employee_id', 'employees.id')
                    ->leftJoin('outlets', 'outlets.id', 'employee_outlet.outlet_id')
                    ->where('employee_outlet.employee_id', Auth::user()->entity_id)
                    ->where('users.company_id', Auth::user()->company_id)
                    ->where('users.user_type_id', 1)
                    ->pluck('employee_outlet.outlet_id')
                    ->toArray();
                $query = $query->whereIn('honda_service_invoices.branch_id', $view_user_outlets_only);
            }

            // dd(count($query));
            $service_invoices = clone $query;
            $service_invoices = $service_invoices->get();
            // dd($service_invoices);

            $service_invoice_header = [
                // 'Type',
                'Outlet', 'Bill / No.', 'Invoice date', 'Item name', 'Account Type', 'Customer / Vendor name', 'Address', 'Zip code', 'PAN number',
                // 'HSN/SAC Code',
                'Before GST amount', 'CGST amount', 'SGST amount', 'IGST amount', 'KFC amount', 'Taxable amount', 'Payment dates', 'Period', 'IT %', 'IT amount', 'Total'];
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
                        $tcs_total = 0;
                        $tcs_percentage = 0;

                        foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                          
                            //TAX CALC AND PUSH
                            $service_invoice_item_taxs = DB::table('honda_service_invoice_item_tax')->where('service_invoice_item_id', $serviceInvoiceItem->id)->get();
                            // dd($service_invoice_item_taxs);
                            if (!is_null($serviceInvoiceItem->serviceItem->sac_code_id)) {
                                if (count($serviceInvoiceItem->serviceItem->taxCode->taxes) > 0) {
                                    // foreach ($serviceInvoiceItem->serviceItem->taxCode->taxes as $key => $value) {
                                    foreach ($service_invoice_item_taxs as $key => $tax) {
                                        // dump($tax);
                                        // dump($value->pivot->percentage);
                                        // FOR CGST
                                        if ($tax->tax_id == 1) {
                                            $cgst_amt = $tax->amount;
                                            // $cgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
                                        }
                                        //FOR SGST
                                        if ($tax->tax_id == 2) {
                                            $sgst_amt = $tax->amount;
                                            // $sgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
                                        }
                                        //FOR IGST
                                        if ($tax->tax_id == 3) {
                                            $igst_amt = $tax->amount;
                                            // $igst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
                                        }
                                        if ($tax->tax_id == 4) {
                                            if ($service_invoice->type_id != 1060) {

                                                if ($service_invoice->address->state_id) {
                                                    // dd('in');
                                                    if (($service_invoice->address->state_id == 3) && ($service_invoice->outlets->state_id == 3)) {
                                                        //3 FOR KERALA
                                                        //check customer state and outlet states are equal KL.  //add KFC tax
                                                        if (!$service_invoice->address->gst_number) {
                                                            //customer dont't have GST
                                                            if (!is_null($serviceInvoiceItem->serviceItem->sac_code_id)) {
                                                                $kfc_amt = $tax->amount;
                                                                //customer have HSN and SAC Code
                                                                // $kfc_amt = round($serviceInvoiceItem->sub_total * 1 / 100, 2);
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        //FOR TCS
                                        if ($tax->tax_id == 5) {
                                            $tcs_total = $tax->amount;
                                            $tcs_percentage = $tax->percentage;
                                        }
                                    }
                                }
                            }
                            // //FOR TCS TAX
                            // if (!empty($serviceInvoiceItem->serviceItem->tcs_percentage) && $serviceInvoiceItem->serviceItem->is_tcs == 1) {
                            //     $document_date = (string) $service_invoice->document_date;
                            //     $date1 = Carbon::createFromFormat('d-m-Y', '31-03-2021');
                            //     $date2 = Carbon::createFromFormat('d-m-Y', $document_date);
                            //     $result = $date1->gte($date2);

                            //     $tcs_limit = Entity::where('entity_type_id', 38)->where('company_id', Auth::user()->company_id)->pluck('name')->first();
                            //     $tcs_percentage = 0;
                            //     if($serviceInvoiceItem->sub_total >= $tcs_limit) {
                            //         $tcs_percentage = $serviceInvoiceItem->serviceItem->tcs_percentage;
                            //         if (!$result) {
                            //             $tcs_percentage = 1;
                            //         }
                            //     }

                            //     $gst_total = 0;
                            //     $gst_total = $cgst_amt + $sgst_amt + $igst_amt + $kfc_amt;
                            //     // $tcs_total = round(($gst_total + $serviceInvoiceItem->sub_total) * $serviceInvoiceItem->serviceItem->tcs_percentage / 100, 2);
                            //     $tcs_total = round(($gst_total + $serviceInvoiceItem->sub_total) * $tcs_percentage / 100, 2);
                            // }

                            // if ($serviceInvoiceItem->serviceItem && ($serviceInvoiceItem->serviceItem->tcs_percentage > 0) && ($serviceInvoiceItem->serviceItem->is_tcs == 1)) {
                            if ($serviceInvoiceItem->serviceItem && $serviceInvoiceItem->serviceItem->tcs_percentage > 0) {
                                // $document_date = (string) $service_invoice->document_date;
                                // $date1 = Carbon::createFromFormat('d-m-Y', '31-03-2021');
                                // $date2 = Carbon::createFromFormat('d-m-Y', $document_date);
                                // $result = $date1->gte($date2);

                                // $tcs_limit = Entity::where('entity_type_id', 38)->where('company_id', Auth::user()->company_id)->pluck('name')->first();
                                // $tcs_percentage = 0;
                                // if($serviceInvoiceItem->sub_total >= $tcs_limit) {
                                //     $tcs_percentage = $serviceInvoiceItem->serviceItem->tcs_percentage;
                                //     if (!$result) {
                                //         $tcs_percentage = 1;
                                //     }
                                // }

                                // dd($serviceInvoiceItem->sub_total);
                                $service_invoice_details[] = [
                                    // $type,
                                    $service_invoice->outlets->code,
                                    $service_invoice->number,
                                    date('d/m/Y', strtotime($service_invoice->document_date)),
                                    $serviceInvoiceItem->serviceItem->name,
                                    $service_invoice->toAccountType->name,
                                    $service_invoice->customer->name,
                                    $service_invoice->address->address_line1 . ',' . $service_invoice->address->address_line2,
                                    $service_invoice->address->pincode,
                                    $service_invoice->customer->pan_number,
                                    // $service_item->taxCode->code,
                                    ($service_invoice->type_id == 1060 ? '-' : '') . (float) $serviceInvoiceItem->sub_total,
                                    $cgst_amt ? ($service_invoice->type_id == 1060 ? '-' : '') . $cgst_amt : 0,
                                    $sgst_amt ? ($service_invoice->type_id == 1060 ? '-' : '') . $sgst_amt : 0,
                                    $igst_amt ? ($service_invoice->type_id == 1060 ? '-' : '') . $igst_amt : 0,
                                    $kfc_amt ? ($service_invoice->type_id == 1060 ? '-' : '') . $kfc_amt : 0,
                                    ($service_invoice->type_id == 1060 ? '-' : '') . ($serviceInvoiceItem->sub_total + $cgst_amt + $sgst_amt + $igst_amt + $kfc_amt),
                                    '-',
                                    '-',
                                    // (float) $serviceInvoiceItem->serviceItem->tcs_percentage,
                                    $tcs_percentage > 0 ? (float) $tcs_percentage : 0,
                                    ($service_invoice->type_id == 1060 ? '-' : '') . $tcs_total,
                                    ($service_invoice->type_id == 1060 ? '-' : '') . ($serviceInvoiceItem->sub_total + $cgst_amt + $sgst_amt + $igst_amt + $kfc_amt + $tcs_total),
                                ];
                            }
                        }
                    }
                }
            }
            // dd($service_invoice_details);
            $file_name = 'cn-dn-tcs-export-' . date('Y-m-d-H-i-s');
            Excel::create($file_name, function ($excel) use ($service_invoice_header, $service_invoice_details) {
                $excel->sheet('cn-dn-tcs', function ($sheet) use ($service_invoice_header, $service_invoice_details) {
                    $sheet->fromArray($service_invoice_details, null, 'A1');
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
            $query = HondaServiceInvoice::with([
                'company',
                'type',
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
                // 'serviceInvoiceItems.eavVarchars',
                // 'serviceInvoiceItems.eavInts',
                // 'serviceInvoiceItems.eavDatetimes',
                'serviceInvoiceItems.eInvoiceUom',
                'serviceInvoiceItems.serviceItem.taxCode',
                'serviceInvoiceItems.serviceItem.taxCode.taxes',
                // 'serviceInvoiceItems.taxes',
            ])
                ->where('document_date', '>=', date('Y-m-d', strtotime($date_range[0])))
                ->where('document_date', '<=', date('Y-m-d', strtotime($date_range[1])))
                ->where('honda_service_invoices.company_id', Auth::user()->company_id)
            // ->where('status_id', 4)
                ->whereIn('status_id', [4, 7, 8])
            // ->where('honda_service_invoices.e_invoice_registration', 1)
            // ->get()
            ;
            // dd(count($query));
            // if (Entrust::can('gst-export-all')) {
            //     $query = $query->where('honda_service_invoices.company_id', Auth::user()->company_id);
            // } elseif (Entrust::can('gst-export-own')) {
            //     $query = $query->where('honda_service_invoices.created_by_id', Auth::user()->id);
            // } elseif (Entrust::can('gst-export-outlet-based')) {
            //     $view_user_outlets_only = User::leftJoin('employees', 'employees.id', 'users.entity_id')
            //         ->leftJoin('employee_outlet', 'employee_outlet.employee_id', 'employees.id')
            //         ->leftJoin('outlets', 'outlets.id', 'employee_outlet.outlet_id')
            //         ->where('employee_outlet.employee_id', Auth::user()->entity_id)
            //         ->where('users.company_id', Auth::user()->company_id)
            //         ->where('users.user_type_id', 1)
            //         ->pluck('employee_outlet.outlet_id')
            //         ->toArray();
            //     $query = $query->whereIn('honda_service_invoices.branch_id', $view_user_outlets_only);
            // }

            $service_invoices = clone $query;
            $service_invoices = $service_invoices->get();
            // dd($service_invoices);

            $service_invoice_header = ['Service Type', 'Supply Type', 'Account Type', 'Customer/Vendor Code', 'Invoice No', 'Invoice Date', 'Ref. Invoice Number', 'Ref. Invoice Date', 'Customer/Vendor Name', 'GSTIN', 'Billing Address', 'Invoice Value', 'HSN/SAC Code', 'Unit Of Measure', 'Qty', 'Item Taxable Value', 'CGST Rate', 'SGST Rate', 'IGST Rate', 'KFC Rate', 'TCS Rate', 'CESS on GST Rate', 'CGST Amount', 'SGST Amount', 'IGST Amount', 'KFC Amount', 'TCS Amount', 'CESS on GST Amount', 'IRN Number',
            ];
            $service_invoice_details = array();

            if (count($service_invoices) > 0) {
                // dd($service_invoice_header);
                if ($service_invoices) {

                    foreach ($service_invoices as $key => $service_invoice) {

                        // if ($service_invoice->type_id == 1060) {
                        //     $type = 'CN';
                        //     $sign_value = '-';
                        // } elseif ($service_invoice->type_id == 1061) {
                        //     $type = 'DN';
                        //     $sign_value = '';
                        // } elseif ($service_invoice->type_id == 1062) {
                        //     $type = 'INV';
                        //     $sign_value = '';
                        // }

                        $gst_total = 0;
                        $cgst_amt = 0;
                        $sgst_amt = 0;
                        $igst_amt = 0;
                        $kfc_amt = 0;
                        $kfc_percentage = 0;
                        $cgst_percentage = 0;
                        $sgst_percentage = 0;
                        $igst_percentage = 0;
                        //FOR TCS TAX
                        $tcs_total = 0;
                        $tcs_percentage = 0;

                        foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                            // $state_id = $service_invoice->address ? $service_invoice->address->state_id ? $service_invoice->address->state_id : '' : '';
                            // $taxes = Tax::getTaxes($serviceInvoiceItem->service_item_id, $service_invoice->branch_id, $service_invoice->customer_id, $service_invoice->to_account_type_id, $state_id);

                            // if (!$taxes['success']) {
                            //     return response()->json(['success' => false, 'error' => $taxes['error']]);
                            // }

                            // $service_item = ServiceItem::with([
                            //     'coaCode',
                            //     'taxCode',
                            //     'taxCode.taxes' => function ($query) use ($taxes) {
                            //         $query->whereIn('tax_id', $taxes['tax_ids']);
                            //     },
                            // ])
                            //     ->find($serviceInvoiceItem->service_item_id);
                            // // dd($service_item->taxCode->code);
                            // if (!$service_item) {
                            //     return response()->json(['success' => false, 'error' => 'Service Item not found']);
                            // }
                            // dd($serviceInvoiceItem->serviceItem);
                            $service_invoice_item_taxs = DB::table('honda_service_invoice_item_tax')->where('service_invoice_item_id', $serviceInvoiceItem->id)->get();
                            //TAX CALC AND PUSH
                            if (!is_null($serviceInvoiceItem->serviceItem->sac_code_id)) {
                                if (count($serviceInvoiceItem->serviceItem->taxCode->taxes) > 0) {
                                    // foreach ($serviceInvoiceItem->serviceItem->taxCode->taxes as $key => $value) {
                                    foreach ($service_invoice_item_taxs as $key => $tax) {

                                        if ($tax->tax_id == 1) {
                                            $cgst_percentage = $tax->percentage;
                                            $cgst_amt = $tax->amount;

                                            // $cgst_percentage = $value->pivot->percentage;
                                            // $cgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
                                        }
                                        //FOR SGST
                                        if ($tax->tax_id == 2) {
                                            $sgst_percentage = $tax->percentage;
                                            $sgst_amt = $tax->amount;

                                            // $sgst_percentage = $value->pivot->percentage;
                                            // $sgst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
                                        }
                                        //FOR IGST
                                        if ($tax->tax_id == 3) {
                                            $igst_percentage = $tax->percentage;
                                            $igst_amt = $tax->amount;

                                            // $igst_percentage = $value->pivot->percentage;
                                            // $igst_amt = round($serviceInvoiceItem->sub_total * $value->pivot->percentage / 100, 2);
                                        }
                                        //FOR KFC
                                        if ($tax->tax_id == 4) {
                                            if ($service_invoice->type_id != 1060) {
                                                if ($service_invoice->address->state_id && $service_invoice->outlets) {
                                                    if ($service_invoice->address->state_id == 3) {
                                                        if (($service_invoice->address->state_id == 3) && ($service_invoice->outlets->state_id == 3)) {
                                                            //3 FOR KERALA
                                                            //check customer state and outlet states are equal KL.  //add KFC tax
                                                            if (!$service_invoice->address->gst_number) {
                                                                //customer dont't have GST
                                                                if (!is_null($serviceInvoiceItem->serviceItem->sac_code_id)) {
                                                                    $kfc_percentage = $tax->percentage;
                                                                    $kfc_amt = $tax->amount;

                                                                    //customer have HSN and SAC Code
                                                                    // $kfc_amt = round($serviceInvoiceItem->sub_total * 1 / 100, 2);
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }

                                        //FOR TCS
                                        if ($tax->tax_id == 5) {
                                            $tcs_percentage = $tax->percentage;
                                            $tcs_total = $tax->amount;
                                        }
                                    }

                                }
                            }

                            // if (!empty($serviceInvoiceItem->serviceItem->tcs_percentage) && $serviceInvoiceItem->serviceItem->is_tcs == 1) {
                            //     dd($serviceInvoiceItem->serviceItem);
                            //     $document_date = (string) $service_invoice->document_date;
                            //     $date1 = Carbon::createFromFormat('d-m-Y', '31-03-2021');
                            //     $date2 = Carbon::createFromFormat('d-m-Y', $document_date);
                            //     $result = $date1->gte($date2);

                            //     $tcs_limit = Entity::where('entity_type_id', 38)->where('company_id', Auth::user()->company_id)->pluck('name')->first();
                            //     $tcs_percentage = 0;
                            //     if($serviceInvoiceItem->sub_total >= $tcs_limit) {
                            //         $tcs_percentage = $serviceInvoiceItem->serviceItem->tcs_percentage;
                            //         if (!$result) {
                            //             $tcs_percentage = 1;
                            //         }
                            //     }
                            //     $gst_total = 0;
                            //     $gst_total = $cgst_amt + $sgst_amt + $igst_amt + $kfc_amt;
                            //     // $tcs_total = round(($gst_total + $serviceInvoiceItem->sub_total) * $serviceInvoiceItem->serviceItem->tcs_percentage / 100, 2);
                            //     $tcs_total = round(($gst_total + $serviceInvoiceItem->sub_total) * $tcs_percentage / 100, 2);
                            // }

                            //FOR CESS ON GST
                            $cess_on_gst_total = 0;
                            if (!empty($serviceInvoiceItem->serviceItem->cess_on_gst_percentage)) {
                                $cess_on_gst_total = round($serviceInvoiceItem->sub_total * $serviceInvoiceItem->serviceItem->cess_on_gst_percentage / 100, 2);
                            }
                            // dump($serviceInvoiceItem->serviceItem->taxCode);

                            if ($service_invoice->outlets && $serviceInvoiceItem->serviceItem->taxCode) {
                                // dump(1);
                                // $tcs_percentage = 0;
                                // if($serviceInvoiceItem->serviceItem->tcs_percentage && $serviceInvoiceItem->serviceItem->is_tcs == 1) {
                                //     $document_date = (string) $service_invoice->document_date;
                                //     $date1 = Carbon::createFromFormat('d-m-Y', '31-03-2021');
                                //     $date2 = Carbon::createFromFormat('d-m-Y', $document_date);
                                //     $result = $date1->gte($date2);

                                //     $tcs_limit = Entity::where('entity_type_id', 38)->where('company_id', Auth::user()->company_id)->pluck('name')->first();
                                //     $tcs_percentage = 0;
                                //     if($serviceInvoiceItem->sub_total >= $tcs_limit) {
                                //         $tcs_percentage = $serviceInvoiceItem->serviceItem->tcs_percentage;
                                //         if (!$result) {
                                //             $tcs_percentage = 1;
                                //         }
                                //     }
                                // }

                                $service_invoice_details[] = [
                                    $service_invoice->type->name,
                                    // $service_invoice->e_invoice_registration == 1 && $service_invoice->irn_number != null ? 'B2B' : 'B2C',
                                    ($service_invoice->address->gst_number && $service_invoice->address->gst_number != '')? 'B2B' : 'B2C',
                                    $service_invoice->toAccountType->name,
                                    $service_invoice->customer->code,
                                    $service_invoice->number,
                                    date('d/m/Y', strtotime($service_invoice->document_date)),
                                    $service_invoice->invoice_number,
                                    $service_invoice->invoice_date ? date('d/m/Y', strtotime($service_invoice->invoice_date)) : '',
                                    $service_invoice->customer->name,
                                    $service_invoice->address->gst_number,
                                    $service_invoice->address->address_line1 . ',' . $service_invoice->address->address_line2,
                                    ($service_invoice->type_id == 1060 ? '-' : '') . ($serviceInvoiceItem->sub_total + $cgst_amt + $sgst_amt + $igst_amt + $kfc_amt + $tcs_total + $cess_on_gst_total),
                                    $serviceInvoiceItem->serviceItem->taxCode->code,
                                    $serviceInvoiceItem->eInvoiceUom->code,
                                    $serviceInvoiceItem->qty,
                                    ($service_invoice->type_id == 1060 ? '-' : '') . (float) ($serviceInvoiceItem->sub_total / $serviceInvoiceItem->qty),
                                    $cgst_percentage,
                                    $sgst_percentage,
                                    $igst_percentage,
                                    $kfc_percentage,
                                    // $serviceInvoiceItem->serviceItem->tcs_percentage,
                                    $tcs_percentage,
                                    $serviceInvoiceItem->serviceItem->cess_on_gst_percentage,
                                    $cgst_amt ? ($service_invoice->type_id == 1060 ? '-' : '') . $cgst_amt : 0,
                                    $sgst_amt ? ($service_invoice->type_id == 1060 ? '-' : '') . $sgst_amt : 0,
                                    $igst_amt ? ($service_invoice->type_id == 1060 ? '-' : '') . $igst_amt : 0,
                                    $kfc_amt ? ($service_invoice->type_id == 1060 ? '-' : '') . $kfc_amt : 0,
                                    $tcs_total ? ($service_invoice->type_id == 1060 ? '-' : '') . $tcs_total : 0,
                                    $cess_on_gst_total ? ($service_invoice->type_id == 1060 ? '-' : '') . $cess_on_gst_total : 0,
                                    $service_invoice->irn_number ? $service_invoice->irn_number : '-',
                                ];
                            }
                        }
                    }
                }
            }
            $file_name = 'cn-dn-gst-export-' . date('Y-m-d-H-i-s');
            Excel::create($file_name, function ($excel) use ($service_invoice_header, $service_invoice_details) {
                $excel->sheet('cn-dn-gst', function ($sheet) use ($service_invoice_header, $service_invoice_details) {
                    $sheet->fromArray($service_invoice_details, null, 'A1');
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

    public function cancelIrn(Request $request)
    {
        // dd($request->all());
        $service_invoice = HondaServiceInvoice::with([
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
            'serviceInvoiceItems.serviceItem.subCategory',
            'serviceInvoiceItems.serviceItem.subCategory.attachment',
            'serviceInvoiceItems.taxes',
        ])->find($request->id);
        // dd($service_invoice);
        // $r = $service_invoice->exportToAxaptaCancel();
        // if (!$r['success']) {
        //     return $r;
        // }

        if ($request->type == "B2C") {
            $service_invoice_save = HondaServiceInvoice::find($request->id);
            $service_invoice_save->status_id = 8; //B2C CANCELED
            $service_invoice_save->save();

            if ($service_invoice->type_id == 1060) {
                $service_invoice->type = 'CREDIT NOTE(CRN)';
            } elseif ($service_invoice->type_id == 1061) {
                $service_invoice->type = 'DEBIT NOTE(DBN)';
            } elseif ($service_invoice->type_id == 1062) {
                $service_invoice->type = 'INVOICE(INV)';
            }

            $r = $service_invoice->exportToAxaptaCancel();
            if (!$r['success']) {
                return $r;
            }

            return response()->json([
                'success' => true,
                'service_invoice' => $service_invoice_save,
                'message' => $service_invoice->type . ' Cancelled Successfully!',
            ]);
        }

        $service_invoice = HondaServiceInvoice::find($request->id);

     

        $authToken = getBdoAuthToken(Auth::user()->company_id);
        $errors = $authToken['errors'];
        $bdo_login_url = $authToken["url"];
        if(!$authToken['success']){
            $errors[] = 'Login Failed!';
            return response()->json(['success' => false, 'error' => 'Login Failed!']);
        }
        $clientid = config('custom.CLIENT_ID');

        $app_secret_key = $authToken['result']['app_secret'];
        $expiry = $authToken['result']['expiry_date'];
        $bdo_authtoken = $authToken['result']['bdo_authtoken'];
        $status = $authToken['result']['status'];
        $bdo_sek = $authToken['result']['bdo_secret'];

        $json_encoded_data =
            json_encode(
            array(
                "supplier_gstin" => $service_invoice->outlets ? ($service_invoice->outlets->gst_number ? $service_invoice->outlets->gst_number : 'N/A') : 'N/A', //FOR TESTING
                // "supplier_gstin" => "09ADDPT0274H009", //FOR TESTING
                // "supplier_gstin" => "33AAGCT6376B1ZF", // FOR TMD SUPPLIER GST TESTING
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
        $encrypt_data = self::encryptAesData($bdo_sek, $json_encoded_data);
        if (!$encrypt_data) {
            $errors[] = 'IRN Encryption Error!';
            return response()->json(['success' => false, 'error' => 'IRN Encryption Error!']);
        }

        // $bdo_cancel_irn_url = 'https://sandboxeinvoiceapi.bdo.in/bdoapi/public/cancelIRN';
        // $bdo_cancel_irn_url = 'https://einvoiceapi.bdo.in/bdoapi/public/cancelIRN'; //LIVE
        $bdo_cancel_irn_url = config('custom.BDO_IRN_CANCEL_URL');

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
        // dd($cancel_irn_output_data);

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
        $api_log->errors = $cancel_irn_output_encode['irnStatus'] != 1 ? $cancel_irn_output_encode['ErrorMsg'] : null;
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

        $service_invoice_save = HondaServiceInvoice::find($request->id);
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

        //CANCEL ENTRY IN AX_EXPORTS
        $r = $service_invoice->exportToAxaptaCancel();
        if (!$r['success']) {
            return $r;
        }

        return response()->json([
            'success' => true,
            'service_invoice' => $service_invoice_save,
            'message' => $service_invoice->type . ' Cancelled Successfully!',
        ]);
    }

     public function searchCustomer(Request $r)
    {   
        try {
            $key = $r->key;
            $axUrl = "GetCustMasterDetails_Honda";
            $this->soapWrapper->add('customer', function ($service) {
                $service
                    ->wsdl('https://tvsapp.tvs.in/OnGo/WebService.asmx?wsdl')
                    ->trace(true);
            });
            $params = ['ACCOUNTNUM' => $key];
            $getResult = $this->soapWrapper->call('customer.'.$axUrl, [$params]);
            $customer_data = $getResult->GetCustMasterDetails_HondaResult;
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
            $api_customer_data = [$customer_data['Table']];
            if (count($api_customer_data) == 0) {
                return response()->json(['success' => false, 'error' => 'Customer Not Available!.']);
            }
             $list = [];
            if (isset($api_customer_data)) {
                 $data = [];
                $array_count = array_filter($api_customer_data, 'is_array');
                 if (count($array_count) > 0) {
                    // if (count($api_customer_data) > 0) {
                    foreach ($api_customer_data as $key => $customer_data) {
                        $data['code'] = $customer_data['ACCOUNTNUM'];
                        $data['name'] = $customer_data['NAME'];
                        $data['mobile_no'] = isset($customer_data['LOCATOR']) && $customer_data['LOCATOR'] != 'Not available' ? $customer_data['LOCATOR'] : null;
                        $data['cust_group'] = isset($customer_data['CUSTGROUP']) && $customer_data['CUSTGROUP'] != 'Not available' ? $customer_data['CUSTGROUP'] : null;
                        $data['pan_number'] = isset($customer_data['PANNO']) && $customer_data['PANNO'] != 'Not available' ? $customer_data['PANNO'] : null;

                        $list[] = $data;
                    }
                } else {
                    $data['code'] = $api_customer_data['ACCOUNTNUM'];
                    $data['name'] = $api_customer_data['NAME'];
                    $data['mobile_no'] = isset($api_customer_data['LOCATOR']) && $api_customer_data['LOCATOR'] != 'Not available' ? $api_customer_data['LOCATOR'] : null;
                    $data['cust_group'] = isset($api_customer_data['CUSTGROUP']) && $api_customer_data['CUSTGROUP'] != 'Not available' ? $api_customer_data['CUSTGROUP'] : null;
                    $data['pan_number'] = isset($api_customer_data['PANNO']) && $api_customer_data['PANNO'] != 'Not available' ? $api_customer_data['PANNO'] : null;

                    $list[] = $data;
                }
            }
            return response()->json($list);
        } catch (\SoapFault $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng in SOAP Service!']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
        }

    }

    public function customerImportNew($code, $job)
    {
        try {
            $key = $code;
            $axUrl = "GetNewCustMasterDetails_Search";
            if(Auth::user()->company_id == 1){
                $axUrl = "GetNewCustMasterDetails_Search_TVS";
            }
            $this->soapWrapper->add('customer', function ($service) {
                $service
                    ->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
                    ->trace(true);
            });
            $params = ['ACCOUNTNUM' => $code];
            $getResult = $this->soapWrapper->call('customer.'.$axUrl, [$params]);
            if(Auth::user()->company_id == 1){
                $customer_data = $getResult->GetNewCustMasterDetails_Search_TVSResult;
            }
            else{
                $customer_data = $getResult->GetNewCustMasterDetails_SearchResult;
            }
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
            $search_list = [];
            if (isset($api_customer_data)) {
                $data = [];
                $array_count = array_filter($api_customer_data, 'is_array');
                if (count($array_count) > 0) {
                    // if (count($api_customer_data) > 0) {
                    foreach ($api_customer_data as $key => $customer_data) {
                        $data['code'] = $customer_data['ACCOUNTNUM'];
                        $data['name'] = $customer_data['NAME'];
                        $data['mobile_no'] = isset($customer_data['LOCATOR']) && $customer_data['LOCATOR'] != 'Not available' ? $customer_data['LOCATOR'] : null;
                        $data['cust_group'] = isset($customer_data['CUSTGROUP']) && $customer_data['CUSTGROUP'] != 'Not available' ? $customer_data['CUSTGROUP'] : null;
                        $data['pan_number'] = isset($customer_data['PANNO']) && $customer_data['PANNO'] != 'Not available' ? $customer_data['PANNO'] : null;

                        $search_list[] = $data;
                    }
                } else {
                    $data['code'] = $api_customer_data['ACCOUNTNUM'];
                    $data['name'] = $api_customer_data['NAME'];
                    $data['mobile_no'] = isset($api_customer_data['LOCATOR']) && $api_customer_data['LOCATOR'] != 'Not available' ? $api_customer_data['LOCATOR'] : null;
                    $data['cust_group'] = isset($api_customer_data['CUSTGROUP']) && $api_customer_data['CUSTGROUP'] != 'Not available' ? $api_customer_data['CUSTGROUP'] : null;
                    $data['pan_number'] = isset($api_customer_data['PANNO']) && $api_customer_data['PANNO'] != 'Not available' ? $api_customer_data['PANNO'] : null;

                    $search_list[] = $data;
                }

                $company = Company::find($job->company_id);
                $ax_company_code = $company ? $company->ax_company_code : 'tvs';

                if ($search_list) {
                    $axUrl = "GetNewCustomerAddress_Search";
                    if(Auth::user()->company_id == 1){
                        $axUrl = "GetNewCustomerAddress_Search_TVS";
                    }
                    $this->soapWrapper->add('address', function ($service) {
                        $service
                            ->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
                            ->trace(true);
                    });
                    $params = ['ACCOUNTNUM' => $code];
                    $getResult = $this->soapWrapper->call('address.'.$axUrl, [$params]);
                    if(Auth::user()->company_id == 1){
                        $customer_data = $getResult->GetNewCustomerAddress_Search_TVSResult;
                    }
                    else{
                        $customer_data = $getResult->GetNewCustomerAddress_SearchResult;
                    }
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

                    $customer = Customer::firstOrNew(['code' => $code,'company_id'=>Auth::user()->company_id]);
                    $customer->company_id = $job->company_id;
                    $customer->name = $search_list[0]['name'];
                    $customer->cust_group = empty($search_list[0]['cust_group']) ? null : $search_list[0]['cust_group'];
                    $customer->gst_number = empty($search_list[0]['gst_number']) ? null : $search_list[0]['gst_number'];
                    $customer->pan_number = empty($search_list[0]['pan_number']) ? null : $search_list[0]['pan_number'];
                    $customer->mobile_no = empty($search_list[0]['mobile_no']) ? null : $search_list[0]['mobile_no'];
                    $customer->address = null;
                    $customer->city = null; //$customer_data['CITY'];
                    $customer->zipcode = null; //$customer_data['ZIPCODE'];
                    $customer->created_at = Carbon::now();
                    $customer->save();

                    $list = [];
                    if ($api_customer_data) {
                        $data = [];
                        if (isset($api_customer_data)) {
                            $array_count = array_filter($api_customer_data, 'is_array');
                            if (count($array_count) > 0) {
                                // dd('mu;l');
                                $address_count = 0;
                                foreach ($api_customer_data as $key => $customer_data) {
                                    if(isset($customer_data['DATAAREAID']) && ($customer_data['DATAAREAID'] == $ax_company_code)){
                                        $address_count = 1;
                                        $address = Address::firstOrNew(['entity_id' => $customer->id, 'ax_id' => $customer_data['RECID']]); //CUSTOMER
                                        // dd($address);
                                        $address->company_id = $job->company_id;
                                        $address->entity_id = $customer->id;
                                        $address->ax_id = $customer_data['RECID'];
                                        $address->gst_number = isset($customer_data['GST_NUMBER']) && $customer_data['GST_NUMBER'] != 'Not available' ? $customer_data['GST_NUMBER'] : null;

                                        $address->ax_customer_location_id = isset($customer_data['CUSTOMER_LOCATION_ID']) ? $customer_data['CUSTOMER_LOCATION_ID'] : null;

                                        $address->address_of_id = 24;
                                        $address->address_type_id = 40;
                                        $address->name = 'Primary Address_' . $customer_data['RECID'];
                                        $address->address_line1 = str_replace('""', '', $customer_data['ADDRESS']);
                                        $city = City::where('name', $customer_data['CITY'])->first();
                                        $state = State::where('code', $customer_data['STATE'])->first();
                                        $address->country_id = $state ? $state->country_id : null;
                                        $address->state_id = $state ? $state->id : null;
                                        $address->city_id = $city ? $city->id : null;
                                        $address->pincode = $customer_data['ZIPCODE'] == 'Not available' ? null : $customer_data['ZIPCODE'];
                                        $address->is_primary = isset($customer_data['ISPRIMARY']) ? $customer_data['ISPRIMARY'] : 0;

                                        $address->save();
                                        $customer_address[] = $address;
                                    }
                                }
                                if($address_count == 0){
                                    $customer_address = [];
                                }
                            } else {
                                if(isset($api_customer_data['DATAAREAID']) && ($api_customer_data['DATAAREAID'] == $ax_company_code)){
                                    // dd('sing');
                                    $address = Address::firstOrNew(['entity_id' => $customer->id, 'ax_id' => $api_customer_data['RECID']]); //CUSTOMER
                                    // dd($address);
                                    $address->company_id = $job->company_id;
                                    $address->entity_id = $customer->id;
                                    $address->ax_id = $api_customer_data['RECID'];

                                    $address->gst_number = isset($api_customer_data['GST_NUMBER']) && $api_customer_data['GST_NUMBER'] != 'Not available' ? $api_customer_data['GST_NUMBER'] : null;

                                    $address->ax_customer_location_id = isset($api_customer_data['CUSTOMER_LOCATION_ID']) ? $api_customer_data['CUSTOMER_LOCATION_ID'] : null;

                                    $address->address_of_id = 24;
                                    $address->address_type_id = 40;
                                    $address->name = 'Primary Address_' . $api_customer_data['RECID'];
                                    $address->address_line1 = str_replace('""', '', $api_customer_data['ADDRESS']);
                                    $city = City::where('name', $api_customer_data['CITY'])->first();
                                    // if ($city) {
                                    $state = State::where('code', $api_customer_data['STATE'])->first();
                                    $address->country_id = $state ? $state->country_id : null;
                                    $address->state_id = $state ? $state->id : null;
                                    // }
                                    $address->city_id = $city ? $city->id : null;
                                    $address->pincode = $api_customer_data['ZIPCODE'] == 'Not available' ? null : $api_customer_data['ZIPCODE'];
                                    $address->is_primary = isset($api_customer_data['ISPRIMARY']) ? $api_customer_data['ISPRIMARY'] : null;
                                    $address->save();
                                    // dd($address);
                                    $customer_address[] = $address;
                                }else{
                                    $customer_address = [];
                                }
                            }
                        } else {
                            $customer_address = [];
                        }
                    }
                }
                return true;

            }
            // return response()->json($list);
        } catch (\SoapFault $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng in SOAP Service!']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
        }

    }

    public function getHondaCustomerAddress(Request $request)
    {
        // dd($request->all());
        try {
            $key = $request->data['code'];
            $axUrl = "GetCustMasterDetails_Honda";
            $this->soapWrapper->add('customer', function ($service) {
                $service
                    ->wsdl('https://tvsapp.tvs.in/OnGo/WebService.asmx?wsdl')
                    ->trace(true);
            });
            $params = ['ACCOUNTNUM' => $key];
            $getResult = $this->soapWrapper->call('customer.'.$axUrl, [$params]);
            $customer_data = $getResult->GetCustMasterDetails_HondaResult;
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
            $api_customer_data = [$customer_data['Table']];
            if (count($api_customer_data) == 0) {
                return response()->json(['success' => false, 'error' => 'Address Not Available!.']);
            }


            $list = [];
            if ($api_customer_data) {
                 $data = [];
                if (isset($api_customer_data)) {
                    $array_count = array_filter($api_customer_data, 'is_array');
                    if (count($array_count) > 0) {
                        //dd('mu;l');
                        $address_count = 0;
                        foreach ($api_customer_data as $key => $customer_data) {
                             if(isset($customer_data['DATAAREAID']) && ($customer_data['DATAAREAID'] != Auth::user()->company->ax_company_code)){

                                $customer = Customer::firstOrNew(['code' => $request->data['code'],'company_id'=>Auth::user()->company_id]);
                                if($customer_data['CITY'])
                                    $city = City::where('name', $customer_data['CITY'])->first();
                                else 
                                    $city = null;
                                if($customer_data['STATE'])
                                    $state = State::where('code', $customer_data['STATE'])->first();
                                else 
                                    $state = null;

                                if(!empty($customer_data['CUSTGROUP'])){

                                    $honda_cust_grp = DB::table('honda_customer_group')
                                                            ->where('code',$customer_data['CUSTGROUP'])->first();
                                    $customer_grp = $honda_cust_grp->id;

                                } else {
                                    $customer_grp = null;
                                }
                                if(!empty($customer_data['DIMENSION'])){

                                    $honda_dimension = DB::table('honda_dept_dimension')
                                                            ->where('dimension_value',$customer_data['DIMENSION'])->first();
                                    $customer_dimension = $honda_dimension->id;

                                } else {
                                    $customer_dimension = null;
                                }
                                $locator = isset( $customer_data['LOCATOR'] ) ? $customer_data['LOCATOR'] : null;
                                $customer->company_id = Auth::user()->company_id;
                                $customer->name = $request->data['name'];
                                $customer->cust_group = $customer_grp;

                                $customer->gst_number = !empty($customer_data['GST_NUMBER']) && $customer_data['GST_NUMBER'] != 'Not available' ? $customer_data['GST_NUMBER'] : null;

                                $customer->pan_number = empty($request->data['pan_number']) ? null : $request->data['pan_number'];
                                $customer->mobile_no = empty($request->data['mobile_no']) ? null : $request->data['mobile_no'];
                                $customer->address = str_replace('""', '', $customer_data['ADDRESS']);;
                                $customer->city = !empty($city) ? $city->id : null; //$customer_data['CITY'];
                                $customer->zipcode = empty($customer_data['ZIPCODE']) || $customer_data['ZIPCODE'] == 'Not available' ? null : $customer_data['ZIPCODE'];
                                $customer->dimension = $customer_dimension;
                                $customer->created_at = Carbon::now();
                                $customer->save();

                                $address_count = 1;
                                $address = Address::firstOrNew(['entity_id' => $customer->id, 'ax_id' =>  $locator]); //CUSTOMER
                                $address->company_id = Auth::user()->company_id;
                                $address->entity_id = $customer->id;
                                $address->ax_id = $locator;
                                $address->gst_number = !empty($customer_data['GST_NUMBER']) && $customer_data['GST_NUMBER'] != 'Not available' ? $customer_data['GST_NUMBER'] : null;

                                $address->ax_customer_location_id = isset($customer_data['LOCATOR']) ? $customer_data['LOCATOR'] : null;

                                $address->address_of_id = 24;
                                $address->address_type_id = 40;
                                $address->name = 'Primary Address_' . $customer_data['OUTLET'];
                                 if(!empty($customer_data['GST_NUMBER']) && $customer_data['GST_NUMBER'] != 'Not available'){
                                    $bdo_response = Customer::getGstDetail($customer_data['GST_NUMBER']);
                                    if (isset($bdo_response->original) && $bdo_response->original['success'] == false) {
                                        return response()->json([
                                            'success' => false,
                                            'error' => 'BDO Error',
                                            'errors' => [$bdo_response->original['error']]
                                        ]);
                                    }


                                    // $customer->name = $bdo_response->original['legal_name'];
                                    $customer->trade_name = $bdo_response->original['trade_name'];
                                    $customer->legal_name = $bdo_response->original['legal_name'];
                                    $customer->save();
                                }

                                $address->address_line1 = str_replace('""', '', $customer_data['ADDRESS']);
                              
                                $address->country_id = $state ? $state->country_id : null;
                                $address->state_id = $state ? $state->id : null;
                                $address->city_id = $city ? $city->id : null;
                                $address->pincode = empty($customer_data['ZIPCODE']) || $customer_data['ZIPCODE'] == 'Not available' ? null : $customer_data['ZIPCODE'];
                                $address->is_primary = isset($customer_data['ISPRIMARY']) ? $customer_data['ISPRIMARY'] : 0;
                                $address->save();
                                $customer_address[] = $address;
                            }
                        }
                        if($address_count == 0){
                            $customer_address = [];
                        }
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
        } catch (\SoapFault $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng in SOAP Service!']);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
        }
    }

    public function searchVendor(Request $r)
    {
        // return Customer::searchCustomer($r);
        // dd(strlen($r->key));
        try {
            $key = $r->key;
            $axUrl = "GetNewVendMasterDetails_Search";
            if(Auth::user()->company_id == 1){
                $axUrl = "GetNewVendMasterDetails_Search_TVS";
            }
            $this->soapWrapper->add('vendor', function ($service) {
                $service
                    ->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
                    ->trace(true);
            });
            $params = ['ACCOUNTNUM' => $r->key];
            $getResult = $this->soapWrapper->call('vendor.'.$axUrl, [$params]);
            if(Auth::user()->company_id == 1){
                $vendor_data = $getResult->GetNewVendMasterDetails_Search_TVSResult;
            }
            else{
                $vendor_data = $getResult->GetNewVendMasterDetails_SearchResult;
            }
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
                        $data['mobile_no'] = isset($vendor_data['LOCATOR']) && $vendor_data['LOCATOR'] != 'Not available' ? $vendor_data['LOCATOR'] : null;
                        $data['vendor_group'] = isset($vendor_data['VENDGROUP']) && $vendor_data['VENDGROUP'] != 'Not available' ? $vendor_data['VENDGROUP'] : null;
                        $data['pan_number'] = isset($vendor_data['PANNO']) && $vendor_data['PANNO'] != 'Not available' ? $vendor_data['PANNO'] : null;

                        $list[] = $data;
                    }
                } else {
                    $data['code'] = $api_vendor_data['ACCOUNTNUM'];
                    $data['name'] = $api_vendor_data['NAME'];
                    $data['mobile_no'] = isset($api_vendor_data['LOCATOR']) && $api_vendor_data['LOCATOR'] != 'Not available' ? $api_vendor_data['LOCATOR'] : null;
                    $data['vendor_group'] = isset($api_vendor_data['VENDGROUP']) && $api_vendor_data['VENDGROUP'] != 'Not available' ? $api_vendor_data['VENDGROUP'] : null;
                    $data['pan_number'] = isset($api_vendor_data['PANNO']) && $api_vendor_data['PANNO'] != 'Not available' ? $api_vendor_data['PANNO'] : null;

                    $list[] = $data;
                }
            }
            return response()->json($list);
        } catch (\SoapFault $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng in SOAP Service!']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
        }

    }

    public function getVendorAddress(Request $request)
    {
        // dd($request->all());
        try {
            $axUrl = "GetNewVendorAddress_Search";
            if(Auth::user()->company_id == 1){
                $axUrl = "GetNewVendorAddress_Search_TVS";
            }
            $this->soapWrapper->add('vendor_address', function ($service) {
                $service
                    ->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
                    ->trace(true);
            });
            $params = ['ACCOUNTNUM' => $request->data['code']];
            $getResult = $this->soapWrapper->call('vendor_address.'.$axUrl, [$params]);
            if(Auth::user()->company_id == 1){
                $vendor_data = $getResult->GetNewVendorAddress_Search_TVSResult;
            }
            else{
                $vendor_data = $getResult->GetNewVendorAddress_SearchResult;
            }
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
            $vendor->gstin = empty($request->data['gst_number']) ? null : $request->data['gst_number'];
            // $vendor->cust_group = empty($request->data['vendor_group']) ? NULL : $request->data['vendor_group'];
            // $vendor->pan_number = empty($request->data['pan_number']) ? NULL : $request->data['pan_number'];
            $vendor->mobile_no = empty($request->data['mobile_no']) ? null : $request->data['mobile_no'];
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
                        $address_count = 0;
                        foreach ($api_vendor_data as $key => $vendor_data) {
                            if(isset($vendor_data['DATAAREAID']) && ($vendor_data['DATAAREAID'] == Auth::user()->company->ax_company_code)){
                                $address_count = 1;
                                $address = Address::firstOrNew(['entity_id' => $vendor->id, 'ax_id' => $vendor_data['RECID']]); //vendor
                                // dd($address);
                                $address->company_id = Auth::user()->company_id;
                                $address->entity_id = $vendor->id;
                                $address->ax_id = $vendor_data['RECID'];
                                $address->gst_number = isset($vendor_data['GST_NUMBER']) ? $vendor_data['GST_NUMBER'] : null;
                                $address->address_of_id = 21;
                                $address->address_type_id = 40;
                                $address->name = 'Primary Address_' . $vendor_data['RECID'];
                                $address->address_line1 = str_replace('""', '', $vendor_data['ADDRESS']);
                                $state = State::firstOrNew(['code' => $vendor_data['STATE']]);
                                if ($state) {
                                    $city = City::firstOrNew(['name' => $vendor_data['CITY'], 'state_id' => $state->id]);
                                    $city->save();
                                }
                                $address->country_id = $state ? $state->country_id : null;
                                $address->state_id = $state ? $state->id : null;
                                $address->city_id = $city ? $city->id : null;
                                $address->pincode = $vendor_data['ZIPCODE'] == 'Not available' ? null : $vendor_data['ZIPCODE'];
                                $address->save();
                                $vendor_address[] = $address;
                            }
                        }
                        if($address_count == 0){
                            $vendor_address = [];
                        }
                    } else {
                        if(isset($api_vendor_data['DATAAREAID']) && ($api_vendor_data['DATAAREAID'] == Auth::user()->company->ax_company_code)){
                            // dd('sing');
                            $address = Address::firstOrNew(['entity_id' => $vendor->id, 'ax_id' => $api_vendor_data['RECID']]); //vendor
                            // dd($address);
                            $address->company_id = Auth::user()->company_id;
                            $address->entity_id = $vendor->id;
                            $address->ax_id = $api_vendor_data['RECID'];
                            // $address->gst_number = isset($api_vendor_data['GST_NUMBER']) ? $api_vendor_data['GST_NUMBER'] : NULL;
                            $address->gst_number = isset($api_vendor_data['GST_NUMBER']) && $api_vendor_data['GST_NUMBER'] != 'Not available' ? $api_vendor_data['GST_NUMBER'] : null;

                            $address->address_of_id = 21;
                            $address->address_type_id = 40;
                            $address->name = 'Primary Address_' . $api_vendor_data['RECID'];
                            $address->address_line1 = str_replace('""', '', $api_vendor_data['ADDRESS']);
                            $state = State::firstOrNew(['code' => $api_vendor_data['STATE']]);
                            if ($state) {
                                $city = City::firstOrNew(['name' => $api_vendor_data['CITY'], 'state_id' => $state->id]);
                                $city->save();
                            }
                            $address->country_id = $state ? $state->country_id : null;
                            $address->state_id = $state ? $state->id : null;
                            // }
                            $address->city_id = $city ? $city->id : null;
                            $address->pincode = $api_vendor_data['ZIPCODE'] == 'Not available' ? null : $api_vendor_data['ZIPCODE'];
                            $address->save();
                            // dd($address);
                            $vendor_address[] = $address;
                        }else{
                            $vendor_address = [];
                        }
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
        } catch (\SoapFault $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng in SOAP Service!']);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
        }
    }

    public function getGstDetails($gstin)
    {
        return Customer::getGstDetail($gstin);
    }

    public static function encryptAesData($encryption_key, $data)
    {
        $method = 'aes-256-ecb';

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

        $encrypted = openssl_encrypt($data, $method, $encryption_key, 0, $iv);

        return $encrypted;
    }

    public static function decryptAesData($encryption_key, $data)
    {
        $method = 'aes-256-ecb';

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

        $decrypted = openssl_decrypt(base64_decode($data), $method, $encryption_key, OPENSSL_RAW_DATA, $iv);
        return $decrypted;
    }

    public function cholaPdfCreate(Request $request)
    {
        // dd($request->all());
        $service_invoice = HondaServiceInvoice::with([
            'company',
            // 'customer',
            'serviceItemCategory',
            'toAccountType',
            'address',
            'outlets',
            'outlets.primaryAddress',
            'outlets.region',
            'sbus',
            'serviceInvoiceItems',
            'serviceInvoiceItems.serviceItem',
            'serviceInvoiceItems.serviceItem.subCategory',
            'serviceInvoiceItems.eavVarchars',
            'serviceInvoiceItems.eavInts',
            'serviceInvoiceItems.eavDatetimes',
            'serviceInvoiceItems.eInvoiceUom',
            'serviceInvoiceItems.serviceItem.taxCode',
            'serviceInvoiceItems.taxes',
        ])->find($request->id);

        // dd($service_invoice);
        foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
            $taxes = $serviceInvoiceItem->taxes;
            $type = $serviceInvoiceItem->serviceItem;
            foreach ($taxes as $array_key_replace => $tax) {
                $serviceInvoiceItem[$tax->name] = $tax;
            }
            //dd($type->sac_code_id);
        }

        $circular_detail = '';
        if (!empty($type->sac_code_id) && ($service_invoice->type_id == 1060)) {
            $service_invoice->sac_code_status = 'CREDIT NOTE(CRN)';
            $service_invoice->document_type = 'CRN';
        } elseif (empty($type->sac_code_id) && ($service_invoice->type_id == 1060)) {
            $service_invoice->sac_code_status = 'FINANCIAL CREDIT NOTE';
            $service_invoice->document_type = 'CRN';
            $circular_detail = '[As per circular No 92/11/2019 dated 07/03/2019]';
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

        if ($service_invoice->qr_image) {
            $service_invoice->qr_image = base_path("storage/app/public/honda-service-invoice/IRN_images/" . $service_invoice->number . '.png') . '.jpg';
        } else {
            $service_invoice->qr_image = '';
        }

        $this->data['service_invoice'] = $service_invoice;
        $this->data['circular_detail'] = $circular_detail;
        // dd($this->data['service_invoice']);

        $tax_list = Tax::where('company_id', 1)->orderBy('id', 'ASC')->get();
        $this->data['tax_list'] = $tax_list;

        $path = storage_path('app/public/honda-service-invoice-pdf/chola-pdf');
        $pathToFile = $path . '/' . $service_invoice->number . '.pdf';
        File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

        $pdf = app('dompdf.wrapper');
        $pdf->getDomPDF()->set_option("enable_php", true);
        $pdf = $pdf->loadView('honda-service-invoices/pdf/chola/index', $this->data);
        // $po_file_name = 'Invoice-' . $this->number . '.pdf';
        File::delete($pathToFile);
        File::put($pathToFile, $pdf->output());

        return response()->json([
            'success' => true,
            'file_name_path' => url('storage/app/public/honda-service-invoice-pdf/chola-pdf') . '/' . $service_invoice->number . '.pdf',
        ]);
    }

    //IMPORTANT FUNCTION FOR IMPORT SEARCH CUSTOMER VIJAY-S 12 JAN 2020 START *******DONT REMOVE**********
    public static function searchCustomerImport($code, $job)
    {
        // return $this->customerImport($code);
        return (new self)->customerImport($code, $job);
    }

    public function customerImport($code, $job)
    {
        // dd($code);
        $axUrl = "GetNewCustMasterDetails_Search";
        if(Auth::user()->company_id == 1){
            $axUrl = "GetNewCustMasterDetails_Search_TVS";
        }
        $this->soapWrapper->add('customer', function ($service) {
            $service
                ->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
                ->trace(true);
        });
        $params = ['ACCOUNTNUM' => $code];
        // dump($code);
        $getResult = $this->soapWrapper->call('customer.'.$axUrl, [$params]);
        // dd($getresult);
        if(Auth::user()->company_id == 1){
            $customer_data = $getResult->GetNewCustMasterDetails_Search_TVSResult;
        }
        else{
            $customer_data = $getResult->GetNewCustMasterDetails_SearchResult;
        }
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
        $search_list = [];
        if (isset($api_customer_data)) {
            $data = [];
            $array_count = array_filter($api_customer_data, 'is_array');
            if (count($array_count) > 0) {
                // if (count($api_customer_data) > 0) {
                foreach ($api_customer_data as $key => $customer_data) {
                    $data['code'] = $customer_data['ACCOUNTNUM'];
                    $data['name'] = $customer_data['NAME'];
                    $data['mobile_no'] = isset($customer_data['LOCATOR']) && $customer_data['LOCATOR'] != 'Not available' ? $customer_data['LOCATOR'] : null;
                    $data['cust_group'] = isset($customer_data['CUSTGROUP']) && $customer_data['CUSTGROUP'] != 'Not available' ? $customer_data['CUSTGROUP'] : null;
                    $data['pan_number'] = isset($customer_data['PANNO']) && $customer_data['PANNO'] != 'Not available' ? $customer_data['PANNO'] : null;

                    $search_list[] = $data;
                }
            } else {
                $data['code'] = $api_customer_data['ACCOUNTNUM'];
                $data['name'] = $api_customer_data['NAME'];
                $data['mobile_no'] = isset($api_customer_data['LOCATOR']) && $api_customer_data['LOCATOR'] != 'Not available' ? $api_customer_data['LOCATOR'] : null;
                $data['cust_group'] = isset($api_customer_data['CUSTGROUP']) && $api_customer_data['CUSTGROUP'] != 'Not available' ? $api_customer_data['CUSTGROUP'] : null;
                $data['pan_number'] = isset($api_customer_data['PANNO']) && $api_customer_data['PANNO'] != 'Not available' ? $api_customer_data['PANNO'] : null;

                $search_list[] = $data;
            }
        }
        // dump($search_list[0]);
        // dd(1);
        $company = Company::find($job->company_id);
        $ax_company_code = $company ? $company->ax_company_code : 'tvs';

        if ($search_list) {
            $axUrl = "GetNewCustomerAddress_Search";
            if(Auth::user()->company_id == 1){
                $axUrl = "GetNewCustomerAddress_Search_TVS";
            }
            $this->soapWrapper->add('address', function ($service) {
                $service
                    ->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
                    ->trace(true);
            });
            $params = ['ACCOUNTNUM' => $code];
            $getResult = $this->soapWrapper->call('address.'.$axUrl, [$params]);
            if(Auth::user()->company_id == 1){
                $customer_data = $getResult->GetNewCustomerAddress_Search_TVSResult;
            }
            else{
                $customer_data = $getResult->GetNewCustomerAddress_SearchResult;
            }
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

            $customer = Customer::firstOrNew(['code' => $code,'company_id'=>Auth::user()->company_id]);
            $customer->company_id = $job->company_id;
            $customer->name = $search_list[0]['name'];
            $customer->cust_group = empty($search_list[0]['cust_group']) ? null : $search_list[0]['cust_group'];
            $customer->gst_number = empty($search_list[0]['gst_number']) ? null : $search_list[0]['gst_number'];
            $customer->pan_number = empty($search_list[0]['pan_number']) ? null : $search_list[0]['pan_number'];
            $customer->mobile_no = empty($search_list[0]['mobile_no']) ? null : $search_list[0]['mobile_no'];
            $customer->address = null;
            $customer->city = null; //$customer_data['CITY'];
            $customer->zipcode = null; //$customer_data['ZIPCODE'];
            $customer->created_at = Carbon::now();
            $customer->save();

            $list = [];
            if ($api_customer_data) {
                $data = [];
                if (isset($api_customer_data)) {
                    $array_count = array_filter($api_customer_data, 'is_array');
                    if (count($array_count) > 0) {
                        // dd('mu;l');
                        $address_count = 0;
                        foreach ($api_customer_data as $key => $customer_data) {
                            if(isset($customer_data['DATAAREAID']) && ($customer_data['DATAAREAID'] == $ax_company_code)){
                                $address_count = 1;
                                $address = Address::firstOrNew(['entity_id' => $customer->id, 'ax_id' => $customer_data['RECID']]); //CUSTOMER
                                // dd($address);
                                $address->company_id = $job->company_id;
                                $address->entity_id = $customer->id;
                                $address->ax_id = $customer_data['RECID'];
                                $address->gst_number = isset($customer_data['GST_NUMBER']) && $customer_data['GST_NUMBER'] != 'Not available' ? $customer_data['GST_NUMBER'] : null;

                                $address->ax_customer_location_id = isset($customer_data['CUSTOMER_LOCATION_ID']) ? $customer_data['CUSTOMER_LOCATION_ID'] : null;

                                $address->address_of_id = 24;
                                $address->address_type_id = 40;
                                $address->name = 'Primary Address_' . $customer_data['RECID'];
                                $address->address_line1 = str_replace('""', '', $customer_data['ADDRESS']);
                                $city = City::where('name', $customer_data['CITY'])->first();
                                $state = State::where('code', $customer_data['STATE'])->first();
                                $address->country_id = $state ? $state->country_id : null;
                                $address->state_id = $state ? $state->id : null;
                                $address->city_id = $city ? $city->id : null;
                                $address->pincode = $customer_data['ZIPCODE'] == 'Not available' ? null : $customer_data['ZIPCODE'];
                                $address->is_primary = isset($customer_data['ISPRIMARY']) ? $customer_data['ISPRIMARY'] : 0;

                                $address->save();
                                $customer_address[] = $address;
                            }
                        }
                        if($address_count == 0){
                            $vendor_address = [];
                        }
                    } else {
                        if(isset($api_customer_data['DATAAREAID']) && ($api_customer_data['DATAAREAID'] == $ax_company_code)){
                            // dd('sing');
                            $address = Address::firstOrNew(['entity_id' => $customer->id, 'ax_id' => $api_customer_data['RECID']]); //CUSTOMER
                            // dd($address);
                            $address->company_id = $job->company_id;
                            $address->entity_id = $customer->id;
                            $address->ax_id = $api_customer_data['RECID'];

                            $address->gst_number = isset($api_customer_data['GST_NUMBER']) && $api_customer_data['GST_NUMBER'] != 'Not available' ? $api_customer_data['GST_NUMBER'] : null;

                            $address->ax_customer_location_id = isset($api_customer_data['CUSTOMER_LOCATION_ID']) ? $api_customer_data['CUSTOMER_LOCATION_ID'] : null;

                            $address->address_of_id = 24;
                            $address->address_type_id = 40;
                            $address->name = 'Primary Address_' . $api_customer_data['RECID'];
                            $address->address_line1 = str_replace('""', '', $api_customer_data['ADDRESS']);
                            $city = City::where('name', $api_customer_data['CITY'])->first();
                            // if ($city) {
                            $state = State::where('code', $api_customer_data['STATE'])->first();
                            $address->country_id = $state ? $state->country_id : null;
                            $address->state_id = $state ? $state->id : null;
                            // }
                            $address->city_id = $city ? $city->id : null;
                            $address->pincode = $api_customer_data['ZIPCODE'] == 'Not available' ? null : $api_customer_data['ZIPCODE'];
                            $address->is_primary = isset($api_customer_data['ISPRIMARY']) ? $api_customer_data['ISPRIMARY'] : null;
                            $address->save();
                            // dd($address);
                            $customer_address[] = $address;
                        }else{
                            $customer_address = [];
                        }
                    }
                } else {
                    $customer_address = [];
                }
            }
        }
        return true;
    }
    //IMPORTANT FUNCTION FOR IMPORT SEARCH CUSTOMER VIJAY-S 12 JAN 2020 END *******DONT REMOVE**********

    public function qrCodeGeneration($service_invoice)
    {
        $cgst_total = 0;
        $sgst_total = 0;
        $igst_total = 0;
        $cgst_amt = 0;
        $sgst_amt = 0;
        $igst_amt = 0;
        $tcs_total = 0;
        $cess_on_gst_total = 0;
        foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
            $item = [];
            // dd($serviceInvoiceItem);

            //GET TAXES
            $state_id = $service_invoice->address ? $service_invoice->address->state_id ? $service_invoice->address->state_id : '' : '';

            $taxes = Tax::getTaxes($serviceInvoiceItem->service_item_id, $service_invoice->branch_id, $service_invoice->customer_id, $service_invoice->to_account_type_id, $state_id);
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
            $tcs_amount = DB::table('honda_service_invoice_item_tax')->where('service_invoice_item_id', $serviceInvoiceItem->id)->where('tax_id', 5)->pluck('amount')->first();
            if ($tcs_amount > 0) {
                $tcs_total += $tcs_amount;
            }
            // if ($service_item->tcs_percentage) {
            //     $document_date = (string) $service_invoice->document_date;
            //     $date1 = Carbon::createFromFormat('d-m-Y', '31-03-2021');
            //     $date2 = Carbon::createFromFormat('d-m-Y', $document_date);
            //     $result = $date1->gte($date2);

            //     $tcs_percentage = $service_item->tcs_percentage;
            //     if (!$result) {
            //         $tcs_percentage = 1;
            //     }

            //     $gst_total = 0;
            //     $gst_total = $cgst_amt + $sgst_amt + $igst_amt;
            //     // $tcs_total += round(($gst_total + $serviceInvoiceItem->sub_total) * $service_item->tcs_percentage / 100, 2);
            //     $tcs_total += round(($gst_total + $serviceInvoiceItem->sub_total) * $tcs_percentage / 100, 2);
            // }

            //FOR CESS on GST TAX
            if ($service_item->cess_on_gst_percentage) {
                $cess_on_gst_total += round(($serviceInvoiceItem->sub_total) * $service_item->cess_on_gst_percentage / 100, 2);
            }
        }

        $qrPaymentApp = QRPaymentApp::where([
            'name' => 'VIMS',
        ])->first();
        if (!$qrPaymentApp) {
            throw new \Exception('QR Payment App not found : VIMS');
        }
        $base_url_with_invoice_details = url(
            '/pay' .
            '?invNo=' . $service_invoice->number .
            '&date=' . date('d-m-Y', strtotime($service_invoice->document_date)) .
            '&invAmt=' . str_replace(',', '', $service_invoice->final_amount) .
            '&oc=' . $service_invoice->outlets->code .
            '&cc=' . $service_invoice->customer->code .
            '&cgst=' . $cgst_total .
            '&sgst=' . $sgst_total .
            '&igst=' . $igst_total .
            '&cess=' . $cess_on_gst_total .
            '&appCode=' . $qrPaymentApp->app_code
        );

        $B2C_images_des = storage_path('app/public/honda-service-invoice/B2C_images');
        File::makeDirectory($B2C_images_des, $mode = 0777, true, true);

        $qr_code_name = $service_invoice->company_id . $service_invoice->number;
        $url = QRCode::URL($base_url_with_invoice_details)->setSize(4)->setOutfile('storage/app/public/honda-service-invoice/B2C_images/' . $qr_code_name . '.png')->png();

        // $file_name = $service_invoice->number . '.png';

        $qr_attachment_path = base_path("storage/app/public/honda-service-invoice/B2C_images/" . $qr_code_name . '.png');
        // dump($qr_attachment_path);
        if (file_exists($qr_attachment_path)) {
            $ext = pathinfo(base_path("storage/app/public/honda-service-invoice/B2C_images/" . $qr_code_name . '.png'), PATHINFO_EXTENSION);
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

                $service_invoice->qr_image = base_path("storage/app/public/honda-service-invoice/B2C_images/" . $qr_code_name . '.png') . '.jpg';
            }
        } else {
            $service_invoice->qr_image = '';
        }

        $service_invoice_save = HondaServiceInvoice::find($service_invoice->id);
        $service_invoice_save->qr_image = $qr_code_name . '.png' . '.jpg';
        $service_invoice_save->save();

        return $service_invoice;
    }

    public function customerUniqueDetailsSearch($code, $job)
    {
        try {
            $key = $code;
            $axUrl = "GetNewCustMasterDetails_Search_Unique";
            if(Auth::user()->company_id == 1){
                $axUrl = "GetNewCustMasterDetails_Search_TVS";
            }
            $this->soapWrapper->add('customer', function ($service) {
                $service
                    ->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
                    ->trace(true);
            });
            $params = ['ACCOUNTNUM' => $code];
            $getResult = $this->soapWrapper->call('customer.'.$axUrl, [$params]);
            if(Auth::user()->company_id == 1){
                $customer_data = $getResult->GetNewCustMasterDetails_Search_TVSResult;
            }
            else{
                $customer_data = $getResult->GetNewCustMasterDetails_Search_uniqueResult;
            }
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
            $search_list = [];
            if (isset($api_customer_data)) {
                $data = [];
                $array_count = array_filter($api_customer_data, 'is_array');
                if (count($array_count) > 0) {
                    // if (count($api_customer_data) > 0) {
                    foreach ($api_customer_data as $key => $customer_data) {
                        $data['code'] = $customer_data['ACCOUNTNUM'];
                        $data['name'] = $customer_data['NAME'];
                        $data['mobile_no'] = isset($customer_data['LOCATOR']) && $customer_data['LOCATOR'] != 'Not available' ? $customer_data['LOCATOR'] : null;
                        $data['cust_group'] = isset($customer_data['CUSTGROUP']) && $customer_data['CUSTGROUP'] != 'Not available' ? $customer_data['CUSTGROUP'] : null;
                        $data['pan_number'] = isset($customer_data['PANNO']) && $customer_data['PANNO'] != 'Not available' ? $customer_data['PANNO'] : null;

                        $search_list[] = $data;
                    }
                } else {
                    $data['code'] = $api_customer_data['ACCOUNTNUM'];
                    $data['name'] = $api_customer_data['NAME'];
                    $data['mobile_no'] = isset($api_customer_data['LOCATOR']) && $api_customer_data['LOCATOR'] != 'Not available' ? $api_customer_data['LOCATOR'] : null;
                    $data['cust_group'] = isset($api_customer_data['CUSTGROUP']) && $api_customer_data['CUSTGROUP'] != 'Not available' ? $api_customer_data['CUSTGROUP'] : null;
                    $data['pan_number'] = isset($api_customer_data['PANNO']) && $api_customer_data['PANNO'] != 'Not available' ? $api_customer_data['PANNO'] : null;

                    $search_list[] = $data;
                }

                $company = Company::find($job->company_id);
                $ax_company_code = $company ? $company->ax_company_code : 'tvs';

                if ($search_list) {
                    $axUrl = "GetNewCustomerAddress_Search";
                    if(Auth::user()->company_id == 1){
                        $axUrl = "GetNewCustomerAddress_Search_TVS";
                    }
                    $this->soapWrapper->add('address', function ($service) {
                        $service
                            ->wsdl('https://tvsapp.tvs.in/ongo/WebService.asmx?wsdl')
                            ->trace(true);
                    });
                    $params = ['ACCOUNTNUM' => $code];
                    $getResult = $this->soapWrapper->call('address.'.$axUrl, [$params]);
                    if(Auth::user()->company_id == 1){
                        $customer_data = $getResult->GetNewCustomerAddress_Search_TVSResult;
                    }
                    else{
                        $customer_data = $getResult->GetNewCustomerAddress_SearchResult;
                    }
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

                    $customer = Customer::firstOrNew([
                        'code' => $code,
                        'company_id'=>Auth::user()->company_id
                    ]);
                    $customer->company_id = $job->company_id;
                    $customer->name = $search_list[0]['name'];
                    $customer->cust_group = empty($search_list[0]['cust_group']) ? null : $search_list[0]['cust_group'];
                    $customer->gst_number = empty($search_list[0]['gst_number']) ? null : $search_list[0]['gst_number'];
                    $customer->pan_number = empty($search_list[0]['pan_number']) ? null : $search_list[0]['pan_number'];
                    $customer->mobile_no = empty($search_list[0]['mobile_no']) ? null : $search_list[0]['mobile_no'];
                    $customer->address = null;
                    $customer->city = null; //$customer_data['CITY'];
                    $customer->zipcode = null; //$customer_data['ZIPCODE'];
                    $customer->created_at = Carbon::now();
                    $customer->save();

                    $list = [];
                    if ($api_customer_data) {
                        $data = [];
                        if (isset($api_customer_data)) {
                            $array_count = array_filter($api_customer_data, 'is_array');
                            if (count($array_count) > 0) {
                                // dd('mu;l');
                                $address_count = 0;
                                foreach ($api_customer_data as $key => $customer_data) {
                                    if(isset($customer_data['DATAAREAID']) && ($customer_data['DATAAREAID'] == $ax_company_code)){
                                        $address_count = 1;
                                        $address = Address::firstOrNew([
                                            'entity_id' => $customer->id,
                                            'ax_id' => $customer_data['RECID']
                                        ]); //CUSTOMER
                                        // dd($address);
                                        $address->company_id = $job->company_id;
                                        $address->entity_id = $customer->id;
                                        $address->ax_id = $customer_data['RECID'];
                                        $address->gst_number = isset($customer_data['GST_NUMBER']) && $customer_data['GST_NUMBER'] != 'Not available' ? $customer_data['GST_NUMBER'] : null;

                                        $address->ax_customer_location_id = isset($customer_data['CUSTOMER_LOCATION_ID']) ? $customer_data['CUSTOMER_LOCATION_ID'] : null;

                                        $address->address_of_id = 24;
                                        $address->address_type_id = 40;
                                        $address->name = 'Primary Address_' . $customer_data['RECID'];
                                        $address->address_line1 = str_replace('""', '', $customer_data['ADDRESS']);
                                        $city = City::where('name', $customer_data['CITY'])->first();
                                        $state = State::where('code', $customer_data['STATE'])->first();
                                        $address->country_id = $state ? $state->country_id : null;
                                        $address->state_id = $state ? $state->id : null;
                                        $address->city_id = $city ? $city->id : null;
                                        $address->pincode = $customer_data['ZIPCODE'] == 'Not available' ? null : $customer_data['ZIPCODE'];
                                        $address->is_primary = isset($customer_data['ISPRIMARY']) ? $customer_data['ISPRIMARY'] : 0;

                                        $address->save();
                                        $customer_address[] = $address;
                                    }
                                }
                                if($address_count == 0){
                                    $customer_address = [];
                                }
                            } else {
                                if(isset($api_customer_data['DATAAREAID']) && ($api_customer_data['DATAAREAID'] == $ax_company_code)){
                                    // dd('sing');
                                    $address = Address::firstOrNew(['entity_id' => $customer->id, 'ax_id' => $api_customer_data['RECID']]); //CUSTOMER
                                    // dd($address);
                                    $address->company_id = $job->company_id;
                                    $address->entity_id = $customer->id;
                                    $address->ax_id = $api_customer_data['RECID'];

                                    $address->gst_number = isset($api_customer_data['GST_NUMBER']) && $api_customer_data['GST_NUMBER'] != 'Not available' ? $api_customer_data['GST_NUMBER'] : null;

                                    $address->ax_customer_location_id = isset($api_customer_data['CUSTOMER_LOCATION_ID']) ? $api_customer_data['CUSTOMER_LOCATION_ID'] : null;

                                    $address->address_of_id = 24;
                                    $address->address_type_id = 40;
                                    $address->name = 'Primary Address_' . $api_customer_data['RECID'];
                                    $address->address_line1 = str_replace('""', '', $api_customer_data['ADDRESS']);
                                    $city = City::where('name', $api_customer_data['CITY'])->first();
                                    // if ($city) {
                                    $state = State::where('code', $api_customer_data['STATE'])->first();
                                    $address->country_id = $state ? $state->country_id : null;
                                    $address->state_id = $state ? $state->id : null;
                                    // }
                                    $address->city_id = $city ? $city->id : null;
                                    $address->pincode = $api_customer_data['ZIPCODE'] == 'Not available' ? null : $api_customer_data['ZIPCODE'];
                                    $address->is_primary = isset($api_customer_data['ISPRIMARY']) ? $api_customer_data['ISPRIMARY'] : null;
                                    $address->save();
                                    // dd($address);
                                    $customer_address[] = $address;
                                }else{
                                    $customer_address = [];
                                }
                            }
                        } else {
                            $customer_address = [];
                        }
                    }
                }
                return true;

            }
            // return response()->json($list);
        } catch (\SoapFault $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng in SOAP Service!']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Somthing went worng!']);
        }

    }

    public function reprintInvoicePdf($service_invoice_id,$gst_number) {
        $errors = [];

        $service_invoice = $service_invoice_pdf = HondaServiceInvoice::with([
            'company',
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
            'serviceInvoiceItems.serviceItem.subCategory',
            'serviceInvoiceItems.serviceItem.subCategory.attachment',
            'serviceInvoiceItems.taxes',
        ])->find($service_invoice_id);

        $bdo_generate_irn_url = config('custom.BDO_IRN_DOC_DETAILS_URL').'?doctype='.$service_invoice->type->name.'&docnum='.$service_invoice->number.'&docdate='.date('d/m/Y', strtotime($service_invoice->document_date));
        //LIVE
        // $bdo_generate_irn_url = 'https://einvoiceapi.bdo.in/bdoapi/public/irnbydocdetails?doctype=INV&docnum=F23ALTNIN000202&docdate=05/05/2022';

        $authToken = getBdoAuthToken($service_invoice->company_id);
        $errors = $authToken['errors'];
        $bdo_login_url = $authToken["url"];
        // dd($authToken);
        if(!$authToken['success']){
            $errors[] = 'Login Failed!';
            return response()->json([
                'success' => false,
                'errors' => ['Login Failed!']
            ]);
        }
        $clientid = config('custom.CLIENT_ID');

        $app_secret_key = $authToken['result']['app_secret'];
        $expiry = $authToken['result']['expiry_date'];
        $bdo_authtoken = $authToken['result']['bdo_authtoken'];
        $status = $authToken['result']['status'];
        $bdo_sek = $authToken['result']['bdo_secret'];

        if($service_invoice->outlets){
           $gst_in_param =  $service_invoice->outlets->gst_number ? $service_invoice->outlets->gst_number : 'N/A';            
        }else{
            $gst_in_param = 'N/A';
        }

        $ch = curl_init($bdo_generate_irn_url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET"); 

        // Set the content type to application/json
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'client_id: ' . $clientid,
            'bdo_authtoken: ' . $bdo_authtoken,
            'Gstin: ' . $gst_number,
        ));

        //Return response instead of outputting
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Execute the POST request
        $generate_irn_output_data = curl_exec($ch);
        curl_close($ch);
        $generate_irn_output = json_decode($generate_irn_output_data, true);

        //DECRYPT WITH APP KEY AND BDO SEK KEY
        $irn_decrypt_data = decryptAesData($bdo_sek, $generate_irn_output['Data']);
        if (!$irn_decrypt_data) {
            $errors[] = 'IRN Decryption Error!';
            return ['success' => false, 'error' => 'IRN Decryption Error!'];
        }
        $final_json_decode = json_decode($irn_decrypt_data);


        //ADDED FOR QUEUE METHOD END
        $service_invoice->customer;
        $service_invoice->address;
        $service_invoice->company->formatted_address = $service_invoice->company->primaryAddress ? $service_invoice->company->primaryAddress->getFormattedAddress() : 'NA';
        $service_invoice->outlets = $service_invoice->outlets ? $service_invoice->outlets : 'NA';
        $service_invoice->customer->formatted_address = $service_invoice->address ? $service_invoice->address->address_line1 : 'NA';

        if ($service_invoice->to_account_type_id == 1440) {
            $state = State::find($service_invoice->address ? $service_invoice->address->state_id : null);
            $service_invoice->address->state_code = $state ? $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-' : '-';
        } else {
            $state = State::find($service_invoice->address ? $service_invoice->address->state_id : null);
            $service_invoice->address->state_code = $state ? $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-' : '-';
        }
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
            $additional_image_name = '';
            $additional_image_path = '';
            foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                // dd($serviceInvoiceItem->serviceItem->subCategory);
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

                if ($serviceInvoiceItem->serviceItem->subCategory->attachment) {
                    $additional_image_name = $serviceInvoiceItem->serviceItem->subCategory->attachment->name;
                    $additional_image_path = base_path('storage/app/public/honda-service-invoice/service-item-sub-category/attachments/');
                }
            }
        }
        // dd($item_count, $item_count_with_tax_code);
        //dd($service_invoice->type_id);
        $type = $serviceInvoiceItem->serviceItem;
        $circular_detail = '';
        if (!empty($type->sac_code_id) && ($service_invoice->type_id == 1060)) {
            $service_invoice->sac_code_status = 'CREDIT NOTE(CRN)';
            $service_invoice->document_type = 'CRN';
        } elseif (empty($type->sac_code_id) && ($service_invoice->type_id == 1060)) {
            $service_invoice->sac_code_status = 'FINANCIAL CREDIT NOTE';
            $service_invoice->document_type = 'CRN';
            $circular_detail = '[As per circular No 92/11/2019 dated 07/03/2019]';
        } elseif ($service_invoice->type_id == 1061) {
            $service_invoice->sac_code_status = 'Tax Invoice(DBN)';
            $service_invoice->document_type = 'DBN';
        } else {
            $service_invoice->sac_code_status = 'Invoice(INV)';
            $service_invoice->document_type = 'INV';
        }

        $eInvoiceConfigId = config("service-invoice-pkg.eInvoiceConfigIdCN");
        if ($service_invoice->type_id == 1060) {
            $service_invoice->type = 'CRN';
            $eInvoiceConfigId = config("service-invoice-pkg.eInvoiceConfigIdCN");
        } elseif ($service_invoice->type_id == 1061) {
            $service_invoice->type = 'DBN';
            $eInvoiceConfigId = config("service-invoice-pkg.eInvoiceConfigIdDN");
        } elseif ($service_invoice->type_id == 1062) {
            $service_invoice->type = 'INV';
            $eInvoiceConfigId = config("service-invoice-pkg.eInvoiceConfigIdINV");
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
        //     $errors[] = 'Customer Pincode Required. Customer Pincode Not Found!';
        //     return [
        //         'success' => false,
        //         'errors' => ['Customer Pincode Required. Customer Pincode Not Found!'],
        //     ];
        //     // DB::commit();

        // }

        if (empty($service_invoice->address->state_id)) {
            $errors[] = 'Customer State Required. Customer State Not Found!';
            return [
                'success' => false,
                'errors' => ['Customer State Required. Customer State Not Found!'],
            ];
        }

        $eInvoiceConfig = EInvoiceConfig::where([
            "config_id"=>$eInvoiceConfigId,"status"=>0,"company_id"=>Auth::user()->company_id
        ])->count();
        $fy_start_date = Config::getConfigName(129380);
        $fy_start_date = date('Y-m-d', strtotime($fy_start_date));
        $inv_date = date('Y-m-d', strtotime($service_invoice->document_date));
        if (!$inv_date)
            $inv_date = date('Y-m-d', strtotime($service_invoice->invoice_date));
        if ($fy_start_date > $inv_date && $service_invoice->e_invoice_registration == 1 && $service_invoice->address->gst_number && $item_count == $item_count_with_tax_code) {
            $eInvoiceConfig = 1;
        }

        // print_r("eInvoiceConfigId");
        // print_r($eInvoiceConfigId);

        if (empty($eInvoiceConfig) && $service_invoice->e_invoice_registration == 1) {
            // dd(1);
            //FOR IRN REGISTRATION
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
                    if (strlen(preg_replace('/\r|\n|:|"/', ",", $service_invoice->address->address_line1)) > 100) {
                        $errors[] = 'Customer Address Maximum Allowed Length 100!';
                        return [
                            'success' => false,
                            'errors' => ['Customer Address Maximum Allowed Length 100!'],
                        ];
                        // DB::commit();
                    }
                }

                // $service_invoice->irnCreate($service_invoice_id);
                // BDO Login
                $api_params = [
                    'type_id' => $service_invoice->type_id,
                    'entity_number' => $service_invoice->number,
                    'entity_id' => $service_invoice->id,
                    'user_id' => Auth::user()->id,
                    'created_by_id' => Auth::user()->id,
                ];

                $authToken = getBdoAuthToken(Auth::user()->company_id);
                // dd($authToken);
                $errors = $authToken['errors'];
                $api_params['errors'] = empty($errors) ? null : json_encode($errors);
                $bdo_login_url = $authToken["url"];
                $api_params['url'] = $bdo_login_url;
                $api_params['src_data'] = isset($authToken["params"])?$authToken["params"]:json_encode([]);
                $api_params['response_data'] = isset($authToken["server_output"])?$authToken["server_output"]:json_encode([]);
                if(!$authToken["success"]){
                    $api_params['message'] = 'Login Failed!';
                    $api_params["status_id"] = 11272;
                    $authToken['api_params'] = $api_params;
                    return $authToken;
                }
                $api_params["status_id"] = 11271;
                $api_params['message'] = 'Login Success!';
                $clientid = config('custom.CLIENT_ID');
                $app_secret_key = $authToken["result"]["app_secret"];
                $expiry = $authToken["result"]["expiry_date"];
                $bdo_authtoken = $authToken["result"]["bdo_authtoken"];
                $status = $authToken["result"]["status"];
                //DECRYPTED BDO SEK KEY
                $decrypt_data_with_bdo_sek = $authToken["result"]["bdo_secret"];
                $api_logs[1] = $api_params;

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
                $cess_on_gst_total = 0;
                foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                    $item = [];
                    // dd($serviceInvoiceItem);

                    //GET TAXES
                    $state_id = $service_invoice->address ? $service_invoice->address->state_id ? $service_invoice->address->state_id : '' : '';

                    $taxes = Tax::getTaxes($serviceInvoiceItem->service_item_id, $service_invoice->branch_id, $service_invoice->customer_id, $service_invoice->to_account_type_id, $state_id);
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

                    // //FOR TCS TAX
                    $tcs_amount = DB::table('honda_service_invoice_item_tax')->where('service_invoice_item_id', $serviceInvoiceItem->id)->where('tax_id', 5)->pluck('amount')->first();
                    if ($tcs_amount > 0) {
                        $tcs_total += $tcs_amount;
                    }
                     
                    //FOR CESS on GST TAX
                    if ($service_item->cess_on_gst_percentage) { 
                        $cess_on_gst_total += round(($serviceInvoiceItem->sub_total) * $service_item->cess_on_gst_percentage / 100, 2);
                    } 

                    $sno++;
                    // $items[] = $item;

                }

        
                $api_params['message'] = 'Success GENSERATE IRN!';

                $api_params['errors'] = null;
                $api_logs[4] = $api_params;
 

                $qr_code_name = $service_invoice->company_id . $service_invoice->number;
                // $url = QRCode::text($final_json_decode->QRCode)->setSize(4)->setOutfile('storage/app/public/honda-service-invoice/IRN_images/' . $service_invoice->number . '.png')->png();
                $url = QRCode::text($final_json_decode->SignedQRCode)->setSize(4)->setOutfile('storage/app/public/honda-service-invoice/IRN_images/' . $qr_code_name . '.png')->png();

                // $file_name = $service_invoice->number . '.png';

                $qr_attachment_path = base_path("storage/app/public/honda-service-invoice/IRN_images/" . $qr_code_name . '.png');
                // dump($qr_attachment_path);
                if (file_exists($qr_attachment_path)) {
                    $ext = pathinfo(base_path("storage/app/public/honda-service-invoice/IRN_images/" . $qr_code_name . '.png'), PATHINFO_EXTENSION);
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

                        $service_invoice->qr_image = base_path("storage/app/public/honda-service-invoice/IRN_images/" . $qr_code_name . '.png') . '.jpg';
                    }
                } else {
                    $service_invoice->qr_image = '';
                }
                // $get_version = json_decode($final_json_decode->Invoice);
                // $get_version = json_decode($get_version->data);

                // $image = '<img src="storage/app/public/honda-service-invoice/IRN_images/' . $final_json_decode->AckNo . '.png" title="IRN QR Image">';
                $service_invoice_save = HondaServiceInvoice::find($service_invoice_id);
                $service_invoice_save->irn_number = $final_json_decode->Irn;
                $service_invoice_save->qr_image = $qr_code_name . '.png' . '.jpg';
                $service_invoice_save->ack_no = $final_json_decode->AckNo;
                $service_invoice_save->ack_date = $final_json_decode->AckDt;
                // $service_invoice_save->version = $get_version->Version;
                // $service_invoice_save->irn_request = $json_encoded_data;
                $service_invoice_save->irn_response = $irn_decrypt_data;
                $service_invoice_save->status_id = 4; //$approval_levels->next_status_id;

                $service_invoice->errors = empty($errors) ? null : json_encode($errors);
                $service_invoice_save->save();

                //SEND TO PDF
                // $service_invoice->version = $get_version->Version;
                $service_invoice->round_off_amount = $service_invoice->round_off_amount;
                $service_invoice->irn_number = $final_json_decode->Irn;
                $service_invoice->ack_no = $final_json_decode->AckNo;
                $service_invoice->ack_date = $final_json_decode->AckDt;

                // dd('no error');

            } else {
                // dd('in');
                //QR CODE ONLY FOR B2C CUSTOMER
                $this->qrCodeGeneration($service_invoice);
                // return ServiceInvoice::b2cQrCodeGenerate();
            }
        } else {
            if(empty($eInvoiceConfig))
                $this->qrCodeGeneration($service_invoice);
        }
       
         
        //----------// ENCRYPTION END //----------//
        $service_invoice['additional_image_name'] = $additional_image_name;
        $service_invoice['additional_image_path'] = $additional_image_path;

        //dd($serviceInvoiceItem->field_groups);
        $this->data['service_invoice_pdf'] = $service_invoice;
        $this->data['circular_detail'] = $circular_detail;
        // dd($this->data['service_invoice_pdf']);

        $tax_list = Tax::where('company_id', 1)->orderBy('id', 'ASC')->get();
        $this->data['tax_list'] = $tax_list;
        // dd($this->data['tax_list']);
        $path = storage_path('app/public/honda-service-invoice-pdf/');
        $pathToFile = $path . '/' . $service_invoice->number . '.pdf';
        $name = $service_invoice->number . '.pdf';
        File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

        $pdf = app('dompdf.wrapper');
        $pdf->getDomPDF()->set_option("enable_php", true);
        $pdf = $pdf->loadView('honda-service-invoices/pdf/index', $this->data);

        // return $pdf->stream('service_invoice.pdf');
        // dd($pdf);
        // $po_file_name = 'Invoice-' . $service_invoice->number . '.pdf';

        File::put($pathToFile, $pdf->output());

        // return [
        //     'success' => true,
        // ];
        // $r['api_logs'] = [];

        //ENTRY IN AX_EXPORTS
        $axaptaExportsCheck = HondaAxaptaExport::where([
            'DocumentNum' => $service_invoice->number,
            'company_id' => $service_invoice->company_id,
        ])->get();

        if(count($axaptaExportsCheck) == 0){ 
            $r = $service_invoice->exportToAxapta();
        }        

        return response()->json([
            'success' => true,
            'file_name_path' => url('storage/app/public/honda-service-invoice-pdf') . '/' . $service_invoice->number . '.pdf',
        ]);
    }
}
