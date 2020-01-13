<?php

namespace Abs\ServiceInvoicePkg;
use Abs\AttributePkg\FieldGroup;
use Abs\CoaPkg\CoaCode;
use Abs\ServiceInvoicePkg\ServiceItem;
use Abs\ServiceInvoicePkg\ServiceItemCategory;
use Abs\ServiceInvoicePkg\ServiceItemSubCategory;
use Abs\TaxPkg\TaxCode;
use App\Http\Controllers\Controller;
use Auth;
use DB;
use Illuminate\Http\Request;
use Validator;
use Yajra\Datatables\Datatables;

class ServiceItemController extends Controller {

	public function __construct() {
	}

	public function getServiceItemFilter() {
		$this->data['extras'] = [
			'main_category_list' => collect(ServiceItemCategory::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
			'sub_category_list' => [],
		];
		return response()->json($this->data);
	}

	public function getServiceItemList(Request $request) {
		$item_code_filter = $request->item_code;
		$item_name_filter = $request->item_name;

		$service_item_list = ServiceItem::withTrashed()
			->select(
				'service_items.id',
				'service_items.code',
				'service_items.name',
				'service_item_sub_categories.name as sub_category',
				'service_item_categories.name as main_category',
				// 'coa_codes.name as coa_code',
				'tax_codes.code as sac_code',
				DB::raw('IF((service_items.deleted_at) IS NULL ,"Active","Inactive") as status'),
				DB::raw('CONCAT(coa_codes.code," / ",coa_codes.name) as coa_code')
			)
			->leftJoin('service_item_sub_categories', 'service_items.sub_category_id', 'service_item_sub_categories.id')
			->leftJoin('service_item_categories', 'service_item_categories.id', 'service_item_sub_categories.category_id')
			->leftJoin('coa_codes', 'service_items.coa_code_id', 'coa_codes.id')
			->leftJoin('tax_codes', 'service_items.sac_code_id', 'tax_codes.id')
			->where('service_items.company_id', Auth::user()->company_id)
			->where(function ($query) use ($item_code_filter) {
				if ($item_code_filter != null) {
					$query->where('service_items.code', 'like', "%" . $item_code_filter . "%");
				}
			})
			->where(function ($query) use ($item_name_filter) {
				if ($item_name_filter != null) {
					$query->where('service_items.name', 'like', "%" . $item_name_filter . "%");
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->main_category_id)) {
					$query->where('service_item_sub_categories.category_id', $request->main_category_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->sub_category_id)) {
					$query->where('service_items.sub_category_id', $request->sub_category_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->coa_code_id)) {
					$query->where('service_items.coa_code_id', $request->coa_code_id);
				}
			})
			->where(function ($query) use ($request) {
				if (!empty($request->sac_code_id)) {
					$query->where('service_items.sac_code_id', $request->sac_code_id);
				}
			})
			->groupBy('service_items.id')
			->orderBy('service_items.code', 'asc');
		// dd($service_item_category_list);

		return Datatables::of($service_item_list)
			->addColumn('name', function ($service_item_list) {
				$status = $service_item_list->status == 'Active' ? 'green' : 'red';
				return '<span class="status-indicator ' . $status . '"></span>' . $service_item_list->name;
			})
			->addColumn('action', function ($service_item_list) {
				$edit_img = asset('public/theme/img/table/cndn/edit.svg');
				$delete_img = asset('public/theme/img/table/cndn/delete.svg');

				return '<a href="#!/service-invoice-pkg/service-item/edit/' . $service_item_list->id . '" class="">
                        <img class="img-responsive" src="' . $edit_img . '" alt="Edit" />
                    	</a>
						<a href="javascript:;" data-toggle="modal" data-target="#delete_service_item"
					onclick="angular.element(this).scope().deleteServiceItem(' . $service_item_list->id . ')" dusk = "delete-btn" title="Delete">
					<img src="' . $delete_img . '" alt="delete" class="img-responsive">
					</a>';
			})
			->make(true);
	}

	public function getServiceItemFormData($id = NULL) {
		if (!$id) {
			$service_item = new ServiceItem;
			$this->data['action'] = 'Add';
		} else {
			$this->data['action'] = 'Edit';
			$service_item = ServiceItem::withTrashed()->with([
				'coaCode',
				'taxCode',
				'subCategory',
				'fieldGroups',
			])->find($id);
			$this->data['sub_category_list'] = collect(ServiceItemSubCategory::select('name', 'id')->where('company_id', Auth::user()->company_id)->where('id', $service_item->sub_category_id)->get())->prepend(['id' => '', 'name' => 'Select Category']);
			$sub_category = ServiceItemSubCategory::where('id', $service_item->sub_category_id)->first();
			if ($sub_category) {
				$main_category = ServiceItemCategory::select('id')->where('id', $sub_category->category_id)->first();
				$service_item->main_category_id = $main_category->id;
			}
			//dd($service_item->id);
			$service_item->field_group_ids = ServiceItem::withTrashed()->join('service_item_field_group', 'service_item_field_group.service_item_id', 'service_items.id')
				->where('service_item_field_group.service_item_id', $service_item->id)
				->pluck('field_group_id')
				->toArray();

//dd($service_item->all_field_group_ids);
			if (!$service_item) {
				return response()->json(['success' => false, 'error' => 'Service Item not found']);
			}
		}

		$service_item->all_field_group_ids = FieldGroup::where('company_id', Auth::user()->company_id)->pluck('id')->toArray();
		$this->data['extras'] = [
			'main_category_list' => collect(ServiceItemCategory::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Category']),
			'coa_code_list' => collect(CoaCode::select('name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Coa Code']),
			'tax_list' => collect(TaxCode::select('code as name', 'id')->where('company_id', Auth::user()->company_id)->get())->prepend(['id' => '', 'name' => 'Select Sac Code']),
			'field_group_list' => FieldGroup::select('name', 'id')->where('category_id', 1040)->where('company_id', Auth::user()->company_id)->get(),
		];
		$this->data['service_item'] = $service_item;
		$this->data['success'] = true;
		return response()->json($this->data);
	}

	public function saveServiceItem(Request $request) {
		//dd($request->all());
		DB::beginTransaction();
		try {

			$error_messages = [
				'name.required' => 'Service item name is required',
				'name.unique' => 'Service item  name has already been taken',
				'code.required' => 'Service item code is required',
				'code.unique' => 'Service item  code has already been taken',
			];

			$validator = Validator::make($request->all(), [
				'name' => [
					'unique:service_items,name,' . $request->id . ',id,company_id,' . Auth::user()->company_id,
					'required:true',
				],
				'code' => [
					'unique:service_items,code,' . $request->id . ',id,company_id,' . Auth::user()->company_id,
					'required:true',
				],
				'sub_category_id' => [
					'required:true',
				],
				'coa_code_id' => [
					'required:true',
				],
				// 'sac_code_id' => [
				// 	'required:true',
				// ],
			], $error_messages);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
			}
			if ($request->id) {
				$service_item = ServiceItem::withTrashed()->find($request->id);
				$service_item->updated_at = date("Y-m-d H:i:s");
				$service_item->updated_by_id = Auth()->user()->id;
			} else {
				$service_item = new ServiceItem();
				$service_item->created_at = date("Y-m-d H:i:s");
				$service_item->created_by_id = Auth()->user()->id;
			}

			if ($request->status == 'Inactive') {
				$service_item->deleted_at = date("Y-m-d H:i:s");
				$service_item->deleted_by_id = Auth()->user()->id;
			} else {
				$service_item->deleted_at = NULL;
				$service_item->deleted_by_id = NULL;
			}
			$service_item->fill($request->all());
			$service_item->company_id = Auth::user()->company_id;
			$service_item->code = $request->code;
			$service_item->name = $request->name;
			$service_item->sub_category_id = $request->sub_category_id;
			$service_item->coa_code_id = $request->coa_code_id;
			$service_item->sac_code_id = $request->sac_code_id;
			$service_item->save();
			//SAVE FIELD-GROUP FIELD
			$service_item->fieldGroups()->sync([]);
			if ($request->field_group_id) {
				$field_groups = json_decode($request->field_group_id);
				$service_item->fieldGroups()->sync($field_groups);
			}
			if ($request->id) {
				$message = 'Service item updated successfully';
			} else {
				$message = 'Service item saved successfully';
			}

			DB::commit();
			return response()->json(['success' => true, 'message' => $message]);
		} catch (Exception $e) {
			DB::rollBack();
			// dd($e->getMessage());
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}
	public function serviceItemDelete($id) {
		$service_item = ServiceItem::withTrashed()->find($id);
		if ($service_item) {
			$service_item->forceDelete();
			return response()->json(['success' => true, 'message' => 'Service item  deleted successfully']);
		}

	}

	public function getSubCategory($id) {
		if ($id) {
			$sub_category_list = collect(ServiceItemSubCategory::select('id', 'name')->where('category_id', $id)->get())->prepend(['id' => '', 'name' => 'Select Sub Category']);
			$this->data['sub_category_list'] = $sub_category_list;
		} else {
			return response()->json(['success' => false, 'error' => 'Category not found']);

		}
		$this->data['success'] = true;
		//dd($this->data);
		return response()->json($this->data);
	}

	public function searchCoaCode(Request $r) {
		return CoaCode::searchCoaCode($r);
	}

	public function searchSacCode(Request $r) {
		return TaxCode::searchSacCode($r);
	}

}
