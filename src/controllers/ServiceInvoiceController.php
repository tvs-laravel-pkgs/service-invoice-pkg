<?php

namespace Abs\ServiceInvoicePkg;
use Abs\ServiceInvoicePkg\ServiceInvoice;
use Abs\ServiceInvoicePkg\ServiceItem;
use Abs\ServiceInvoicePkg\ServiceItemCategory;
use Abs\ServiceInvoicePkg\ServiceItemSubCategory;
use App\Customer;
use App\Http\Controllers\Controller;
use App\Outlet;
use App\Sbu;
use Auth;
use Illuminate\Http\Request;
// use DB;
// use Validator;
use Yajra\Datatables\Datatables;

class ServiceInvoiceController extends Controller {

	public function __construct() {
	}

	public function getServiceInvoiceList() {
		$service_invoice_list = ServiceInvoice::withTrashed()
			->select(
				'service_invoices.id',
				'service_invoices.number',
				'service_invoices.invoice_date',
				'service_invoices.total as invoice_amount',
				'outlets.code as branch',
				'sbus.name as sbu',
				'service_item_categories.name as category',
				'service_item_sub_categories.name as sub_category',
				'customers.code as customer_code',
				'customers.name as customer_name'
			)
			->join('outlets', 'outlets.id', 'service_invoices.branch_id')
			->join('sbus', 'sbus.id', 'service_invoices.sbu_id')
			->join('service_item_sub_categories', 'service_item_sub_categories.id', 'service_invoices.sub_category_id')
			->join('service_item_categories', 'service_item_categories.id', 'service_item_sub_categories.category_id')
			->join('customers', 'customers.id', 'service_invoices.customer_id')
			->where('service_invoices.company_id', Auth::user()->company_id)
			->groupBy('service_invoices.id')
			->orderBy('service_invoices.id', 'Desc');

		return Datatables::of($service_invoice_list)
			->addColumn('child_checkbox', function ($service_invoice_list) {
				$checkbox = "<td><div class='table-checkbox'><input type='checkbox' id='child_" . $service_invoice_list->id . "' /><label for='child_" . $service_invoice_list->id . "'></label></div></td>";

				return $checkbox;
			})
			->addColumn('action', function ($service_invoice_list) {

				$img_edit = asset('public/theme/img/table/cndn/edit.svg');
				$img_view = asset('public/theme/img/table/cndn/view.svg');
				$img_download = asset('public/theme/img/table/cndn/download.svg');
				$img_delete = asset('public/theme/img/table/cndn/delete.svg');

				return '<a href="#!" class="">
                                        <img class="img-responsive" src="' . $img_view . '" alt="View" />
                                    </a>
                        <a href="#!/service-invoice-pkg/service-invoice/edit/' . $service_invoice_list->id . '" class="">
                        <img class="img-responsive" src="' . $img_edit . '" alt="Edit" />
                    	</a>
						<a href="#!" class="">
                                        <img class="img-responsive" src="' . $img_download . '" alt="Download" />
                                    </a>';
			})
			->rawColumns(['child_checkbox', 'action'])
			->make(true);
	}

	public function getFormData($id = NULL) {

		if (!$id) {
			$service_invoice = new ServiceInvoice;
			$service_invoice->invoice_date = date('d-m-Y');
			$this->data['action'] = 'Add';
		} else {
			$service_invoice = ServiceInvoice::with([
				'serviceInvoiceItems',
				'serviceItemSubCategory',
			])->find($id);
			if (!$service_invoice) {
				return response()->json(['success' => false, 'error' => 'Service Invoice not found']);
			}
			$this->data['action'] = 'Edit';
		}

		$this->data['extras'] = [
			'branch_list' => collect(Outlet::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Branch']),
			'sbu_list' => collect(Sbu::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Sbu']),
			'category_list' => collect(ServiceItemCategory::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
			'sub_category_list' => [],
		];
		$this->data['service_invoice'] = $service_invoice;
		$this->data['success'] = true;
		return response()->json($this->data);
	}

	public function getServiceItemSubCategories($service_item_category_id) {
		return ServiceItemSubCategory::getServiceItemSubCategories($service_item_category_id);
	}

	public function searchCustomer(Request $r) {
		return Customer::searchCustomer($r);
	}

	public function getCustomerDetails(Request $request) {
		$customer = Customer::find($request->customer_id);
		if (!$customer) {
			return response()->json(['success' => false, 'error' => 'Customer not found']);
		}
		$customer->formatted_address = $customer->getFormattedAddress();
		return response()->json([
			'success' => true,
			'customer' => $customer,
		]);
	}

	public function searchServiceItem(Request $r) {
		return ServiceItem::searchServiceItem($r);
	}

	public function getServiceItemDetails(Request $request) {
		$service_item = ServiceItem::find($request->service_item_id);
		if (!$service_item) {
			return response()->json(['success' => false, 'error' => 'Service Item not found']);
		}
		return response()->json([
			'success' => true,
			'service_item' => $service_item,
		]);
	}

	public function saveFieldGroup(Request $request) {
		// dd($request->all());
		DB::beginTransaction();
		try {

			$error_messages = [
				'name.required' => 'Field group name is required',
				'name.unique' => 'Field group name has already been taken',
			];

			$validator = Validator::make($request->all(), [
				'name' => [
					'unique:field_groups,name,' . $request->id . ',id,company_id,' . Auth::user()->company_id . ',category_id,' . $request->category_id,
					'required:true',
				],
			], $error_messages);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
			}

			//VALIDATE FIELD-GROUP FIELD UNIQUE
			if ($request->fields && !empty($request->fields)) {
				$field_group_fields = collect($request->fields)->pluck('id')->toArray();
				$field_group_fields_unique = array_unique($field_group_fields);
				if (count($field_group_fields) != count($field_group_fields_unique)) {
					return response()->json(['success' => false, 'errors' => ['Field has already been taken']]);
				}
			}

			if ($request->id) {
				$field_group = FieldGroup::withTrashed()->find($request->id);
				$field_group->updated_at = date("Y-m-d H:i:s");
				$field_group->updated_by_id = Auth()->user()->id;
			} else {
				$field_group = new FieldGroup();
				$field_group->created_at = date("Y-m-d H:i:s");
				$field_group->created_by_id = Auth()->user()->id;
			}

			if ($request->status == 'Inactive') {
				$field_group->deleted_at = date("Y-m-d H:i:s");
				$field_group->deleted_by_id = Auth()->user()->id;
			} else {
				$field_group->deleted_at = NULL;
				$field_group->deleted_by_id = NULL;
			}
			$field_group->fill($request->all());
			$field_group->company_id = Auth::user()->company_id;
			$field_group->save();

			//SAVE FIELD-GROUP FIELD
			$field_group->fields()->sync([]);
			if ($request->fields) {
				if (!empty($request->fields)) {
					foreach ($request->fields as $key => $field) {
						$is_required = $field['is_required'] == 'Yes' ? 1 : 0;
						$field_group->fields()->attach($field['id'], ['is_required' => $is_required]);
					}
				}
			}

			DB::commit();
			return response()->json(['success' => true, 'message' => 'Field group saved successfully']);
		} catch (Exception $e) {
			DB::rollBack();
			// dd($e->getMessage());
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}

}
