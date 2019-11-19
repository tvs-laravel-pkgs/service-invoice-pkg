<?php

namespace Abs\ServiceInvoicePkg;
use Abs\AttributePkg\Field;
use Abs\AttributePkg\FieldGroup;
use Abs\AttributePkg\FieldSourceTable;
use Abs\SerialNumberPkg\SerialNumberGroup;
use Abs\ServiceInvoicePkg\ServiceInvoice;
use Abs\ServiceInvoicePkg\ServiceInvoiceItem;
use Abs\ServiceInvoicePkg\ServiceItem;
use Abs\ServiceInvoicePkg\ServiceItemCategory;
use Abs\ServiceInvoicePkg\ServiceItemSubCategory;
use Abs\TaxPkg\Tax;
use App\Attachment;
use App\Company;
use App\Customer;
use App\FinancialYear;
use App\Http\Controllers\Controller;
use App\Outlet;
use App\Sbu;
use Auth;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PDF;
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
						<a href="' . route("downloadPdf", ["id" => $service_invoice_list->id]) . '" class="">
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
				'serviceInvoiceItems.eavVarchars',
				'serviceInvoiceItems.eavInts',
				'serviceInvoiceItems.taxes',
				'serviceItemSubCategory',
			])->find($id);
			if (!$service_invoice) {
				return response()->json(['success' => false, 'error' => 'Service Invoice not found']);
			}

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
					$field_group_ids = array_unique(array_merge($eav_varchar_field_group_ids, $eav_int_field_group_ids));

					if (!empty($field_group_ids)) {
						foreach ($field_group_ids as $fg_key => $fg_id) {
							$fd_varchar_array = [];
							$fd_int_array = [];
							$fd_main_varchar_array = [];
							$fd_varchar_array = DB::table('eav_varchar')->where('entity_type_id', 1040)->where('entity_id', $serviceInvoiceItem->id)->where('field_group_id', $fg_id)->select('field_id as id', 'value')->get()->toArray();
							$fd_int_array = DB::table('eav_int')->where('entity_type_id', 1040)->where('entity_id', $serviceInvoiceItem->id)->where('field_group_id', $fg_id)->select('field_id as id', 'value')->get()->toArray();
							$fd_main_varchar_array = array_merge($fd_varchar_array, $fd_int_array);
							$serviceInvoiceItem->field_groups = [
								$fg_key => [
									'id' => $fg_id,
									'fields' => $fd_main_varchar_array,
								],
							];
						}
					}

					//TAX CALC
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
			// 'sbu_list' => collect(Sbu::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Sbu']),
			'sbu_list' => [],
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

	public function searchServiceItem(Request $r) {
		return ServiceItem::searchServiceItem($r);
	}

	public function getServiceItemDetails(Request $request) {
		if ($request->btn_action == 'add') {
			$service_item = ServiceItem::with([
				'fieldGroups',
				'fieldGroups.fields',
				'fieldGroups.fields.fieldType',
				'coaCode',
				'taxCode',
				'taxCode.taxes',
			])
				->find($request->service_item_id);
			if (!$service_item) {
				return response()->json(['success' => false, 'error' => 'Service Item not found']);
			}

			if (count($service_item->fieldGroups) > 0) {
				foreach ($service_item->fieldGroups as $key => $fieldGroup) {
					if (count($fieldGroup->fields) > 0) {
						foreach ($fieldGroup->fields as $key => $field) {
							if ($field->type_id == 1) {
								if ($field->list_source_id == 1180) {
									$source_table = FieldSourceTable::find($field->source_table_id);
									if (!$source_table) {
										$field->get_list = [];
									} else {
										$nameSpace = '\\App\\';
										$entity = $source_table->model;
										$model = $nameSpace . $entity;
										$placeholder = 'Select ' . $entity;
										$field->get_list = collect($model::select('name', 'id')->get())->prepend(['id' => '', 'name' => $placeholder]);
									}
								} else {
									$field->get_list = [];
								}
							}
						}
					}
				}
			}
		} else {
			$service_item = ServiceItem::with([
				'coaCode',
				'taxCode',
				'taxCode.taxes',
			])
				->find($request->service_item_id);
			if (!$service_item) {
				return response()->json(['success' => false, 'error' => 'Service Item not found']);
			}
			if (count($request->field_groups) > 0) {
				//FIELDGROUPS
				foreach ($request->field_groups as $fg_key => $fg) {
					$fg_v = FieldGroup::find($fg['id']);

					//FIELDS
					if (count($fg['fields']) > 0) {
						foreach ($fg['fields'] as $fd_key => $fd) {
							$field = Field::find($fd['id']);
							$fg_v->fields[$fd_key] = Field::find($fd['id']);
							if ($field->type_id == 1) {
								if ($field->list_source_id == 1180) {
									$source_table = FieldSourceTable::find($field->source_table_id);
									if (!$source_table) {
										$fg_v->fields[$fd_key]->get_list = [];
										$fg_v->fields[$fd_key]->value = $fd['value'];
									} else {
										$nameSpace = '\\App\\';
										$entity = $source_table->model;
										$model = $nameSpace . $entity;
										$placeholder = 'Select ' . $entity;
										$fg_v->fields[$fd_key]->get_list = collect($model::select('name', 'id')->get())->prepend(['id' => '', 'name' => $placeholder]);
										$fg_v->fields[$fd_key]->value = $fd['value'];
									}
								} else {
									$fg_v->fields[$fd_key]->get_list = [];
									$fg_v->fields[$fd_key]->value = $fd['value'];
								}
							} elseif ($field->type_id == 3 || $field->type_id == 4) {
								$fg_v->fields[$fd_key]->value = $fd['value'];
							} elseif ($field->type_id == 10) {
								if ($field->list_source_id == 1180) {
									$source_table = FieldSourceTable::find($field->source_table_id);
									if (!$source_table) {
										$fg_v->fields[$fd_key]->autoval = [];
									} else {
										$nameSpace = '\\App\\';
										$entity = $source_table->model;
										$model = $nameSpace . $entity;
										$fg_v->fields[$fd_key]->autoval = $model::where('id', $fd['value'])
											->select(
												'id',
												'name',
												'code'
											)
											->first();
									}
								} else {
									$fg_v->fields[$fd_key]->autoval = [];
								}
							}
							$is_required = DB::table('field_group_field')->where('field_group_id', $fg['id'])->where('field_id', $fd['id'])->first();
							$fg_v->fields[$fd_key]->pivot = [];
							if ($is_required) {
								$fg_v->fields[$fd_key]->pivot = [
									'is_required' => $is_required->is_required,
								];
							} else {
								$fg_v->fields[$fd_key]->pivot = [
									'is_required' => 0,
								];
							}

						}
					}
					//FIELDGROUPS FIELD PUSH
					$service_item->field_groups = [
						$fg_key => $fg_v,
					];
				}
			}
		}

		return response()->json(['success' => true, 'service_item' => $service_item]);
	}

	public function getServiceItem(Request $request) {
		// dd($request->all());
		$service_item = ServiceItem::with([
			'coaCode',
			'taxCode',
			'taxCode.taxes',
			// 'fieldGroups',
			// 'fieldGroups.fields',
			// 'fieldGroups.fields.fieldType',
		])
			->find($request->service_item_id);
		if (!$service_item) {
			return response()->json(['success' => false, 'error' => 'Service Item not found']);
		}

		//TAX CALC AND PUSH
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
		// dump(Field::get()->keyBy('id'));
		// dd($request->all());
		DB::beginTransaction();
		try {

			//SERIAL NUMBER GENERATION & VALIDATION
			if (!$request->id) {
				//GET FINANCIAL YEAR ID BY DOCUMENT DATE
				$document_date_year = date('Y', strtotime($request->document_date));
				$financial_year = FinancialYear::where('from', $document_date_year)
					->first();
				if (!$financial_year) {
					return response()->json(['success' => false, 'errors' => ['No Serial number found']]);
				}
				$branch = Outlet::where('id', $request->branch_id)->first();

				//GENERATE SERVICE INVOICE NUMBER
				$generateNumber = SerialNumberGroup::generateNumber(1, $financial_year->id, $branch->state_id, $branch->id);
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
			], $error_messages);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
			}

			//VALIDATE SERVICE INVOICE ITEMS
			if (!$request->service_invoice_items) {
				return response()->json(['success' => false, 'errors' => ['Service invoice item is required']]);
			}

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
				$message = 'Service invoice added successfully';
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

						//SAVE SERVICE INVOICE ITEMS FIELD GROUPS AND RESPECTIVE FIELDS
						$fields = Field::get()->keyBy('id');
						$service_invoice_item->eavVarchars()->sync([]);
						$service_invoice_item->eavInts()->sync([]);
						if (isset($val['field_groups']) && !empty($val['field_groups'])) {
							foreach ($val['field_groups'] as $fg_key => $fg_value) {
								if (isset($fg_value['fields']) && !empty($fg_value['fields'])) {
									foreach ($fg_value['fields'] as $f_key => $f_value) {
										//SAVE FREE TEXT | NUMERIC TEXT FIELDS
										if ($fields[$f_value['id']]->type_id == 3) {
											$service_invoice_item->eavVarchars()->attach(1040, ['field_group_id' => $fg_value['id'], 'field_id' => $f_value['id'], 'value' => $f_value['value']]);

										} elseif ($fields[$f_value['id']]->type_id == 1 || $fields[$f_value['id']]->type_id == 10) {
											//SAVE SSDD | MSDD
											$service_invoice_item->eavInts()->attach(1040, ['field_group_id' => $fg_value['id'], 'field_id' => $f_value['id'], 'value' => $f_value['value']]);
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
			// dd(' == exist ==');
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

	public function downloadPdf($service_invoice_pdf_id) {

		$service_invoice_pdf = ServiceInvoice::with([
			'company',
			'customer',
			'outlets',
			'outlets.region',
			'sbus',
			'serviceInvoiceItems',
			'serviceInvoiceItems.serviceItem',
			'serviceInvoiceItems.serviceItem.taxCode',
			'serviceInvoiceItems.taxes',
		])->find($service_invoice_pdf_id);

		$service_invoice_pdf->company->formatted_address = $service_invoice_pdf->company->primaryAddress ? $service_invoice_pdf->company->primaryAddress->getFormattedAddress() : 'NA';
		$service_invoice_pdf->outlets->formatted_address = $service_invoice_pdf->outlets->primaryAddress ? $service_invoice_pdf->outlets->primaryAddress->getFormattedAddress() : 'NA';
		$service_invoice_pdf->customer->formatted_address = $service_invoice_pdf->customer->primaryAddress ? $service_invoice_pdf->customer->primaryAddress->getFormattedAddress() : 'NA';

		if (count($service_invoice_pdf->serviceInvoiceItems) > 0) {
			$array_key_replace = [];
			foreach ($service_invoice_pdf->serviceInvoiceItems as $key => $serviceInvoiceItem) {
				$taxes = $serviceInvoiceItem->taxes;
				foreach ($taxes as $array_key_replace => $tax) {
					$serviceInvoiceItem[$tax->name] = $tax;
				}
			}
		}
		$this->data['service_invoice_pdf'] = $service_invoice_pdf;

		$tax_list = Tax::where('company_id', Auth::user()->company_id)->get();
		$this->data['tax_list'] = $tax_list;
		$headers = array(
			'Content-Type: application/pdf',
		);
		$pdf = PDF::loadView('service-invoices/pdf/index', $this->data);
		$po_file_name = 'Invoice-' . $service_invoice_pdf->number . '.pdf';

		return $pdf->download($po_file_name, $headers);
	}

}
