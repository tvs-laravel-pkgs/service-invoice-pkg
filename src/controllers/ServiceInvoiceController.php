<?php

namespace Abs\ServiceInvoicePkg;
use Abs\ServiceInvoicePkg\ServiceInvoice;
use Abs\ServiceInvoicePkg\ServiceInvoiceItem;
use Abs\ServiceInvoicePkg\ServiceItem;
use Abs\ServiceInvoicePkg\ServiceItemCategory;
use Abs\ServiceInvoicePkg\ServiceItemSubCategory;
use Abs\TaxPkg\Tax;
use App\Attachment;
use App\Customer;
use App\Http\Controllers\Controller;
use App\Outlet;
use App\Sbu;
use Auth;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Validator;
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
				'attachments',
				'customer',
				'serviceInvoiceItems',
				'serviceInvoiceItems.serviceItem',
				'serviceInvoiceItems.taxes',
				'serviceItemSubCategory',
			])->find($id);
			if (!$service_invoice) {
				return response()->json(['success' => false, 'error' => 'Service Invoice not found']);
			}

			$gst_total = 0;
			if (count($service_invoice->serviceInvoiceItems) > 0) {
				foreach ($service_invoice->serviceInvoiceItems as $key => $serviceInvoiceItem) {
					if (count($serviceInvoiceItem->taxes) > 0) {
						foreach ($serviceInvoiceItem->taxes as $key => $value) {
							$gst_total += intval($value->pivot->amount);
							$serviceInvoiceItem[$value->name] = [
								'amount' => intval($value->pivot->amount),
								'percentage' => intval($value->pivot->percentage),
							];
						}
					}
					$serviceInvoiceItem->total = intval($serviceInvoiceItem->sub_total) + intval($gst_total);
					$serviceInvoiceItem->code = $serviceInvoiceItem->serviceItem->code;
					$serviceInvoiceItem->name = $serviceInvoiceItem->serviceItem->name;
				}
			}

			$this->data['action'] = 'Edit';
		}

		$this->data['extras'] = [
			'branch_list' => collect(Outlet::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Branch']),
			'sbu_list' => collect(Sbu::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Sbu']),
			'tax_list' => Tax::select('name', 'id')->where('company_id', Auth::user()->company_id)->get(),
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
		$service_item = ServiceItem::with([
			'fieldGroups',
			'fieldGroups.fields',
			'coaCode',
			'taxCode',
			'taxCode.taxes',
		])
			->find($request->service_item_id);
		if (!$service_item) {
			return response()->json(['success' => false, 'error' => 'Service Item not found']);
		}
		return response()->json(['success' => true, 'service_item' => $service_item]);
	}

	public function getServiceItem(Request $request) {
		// dump($request->all());
		$service_item = ServiceItem::with([
			'coaCode',
			'taxCode',
			'taxCode.taxes',
		])
			->find($request->service_item_id);
		if (!$service_item) {
			return response()->json(['success' => false, 'error' => 'Service Item not found']);
		}

		$gst_total = 0;
		if (count($service_item->taxCode->taxes) > 0) {
			foreach ($service_item->taxCode->taxes as $key => $value) {
				$gst_total += intval(($value->pivot->percentage / 100) * ($request->qty * $request->amount));
				$service_item[$value->name] = [
					'amount' => intval(($value->pivot->percentage / 100) * ($request->qty * $request->amount)),
					'percentage' => intval($value->pivot->percentage),
				];
			}
		}

		$service_item->service_item_id = $service_item->id;
		$service_item->id = null;
		$service_item->description = $request->description;
		$service_item->qty = $request->qty;
		$service_item->rate = $request->amount;
		$service_item->sub_total = intval($request->qty * $request->amount);
		$service_item->total = intval($request->qty * $request->amount) + $gst_total;

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
				'invoice_date.required' => 'Invoice date is required',
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
				'invoice_date' => [
					'required:true',
				],
				'document_date' => [
					'required:true',
				],
				'customer_id' => [
					'required:true',
				],
				'proposal_attachments.*' => [
					'required:true',
					'mimes:jpg,jpeg,png,bmp',
				],
				'number' => [
					// 'unique:service_invoices,number,' . $request->id . ',id,company_id,' . Auth::user()->company_id,
					'required:true',
				],
			], $error_messages);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
			}

			if ($request->id) {
				$service_invoice = ServiceInvoice::find($request->id);
				$service_invoice->updated_at = date("Y-m-d H:i:s");
				$service_invoice->updated_by_id = Auth()->user()->id;
			} else {
				$service_invoice = new ServiceInvoice();
				$service_invoice->created_at = date("Y-m-d H:i:s");
				$service_invoice->created_by_id = Auth()->user()->id;
			}

			$service_invoice->fill($request->all());
			$service_invoice->company_id = Auth::user()->company_id;
			$service_invoice->save();

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
			return response()->json(['success' => true, 'message' => 'Service invoice saved successfully']);
		} catch (Exception $e) {
			DB::rollBack();
			// dd($e->getMessage());
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}

}
