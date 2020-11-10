<?php

namespace Abs\ServiceInvoicePkg;
use Abs\ServiceInvoicePkg\ServiceItemCategory;
use App\Attachment;
use App\Http\Controllers\Controller;
use Auth;
use DB;
use File;
use Illuminate\Http\Request;
use Storage;
use Validator;
use Yajra\Datatables\Datatables;

class ServiceItemCategoryController extends Controller {

	public function __construct() {
	}

	public function getServiceItemCategoryList() {
		$service_item_category_list = ServiceItemCategory::withTrashed()
			->select(
				'service_item_categories.id',
				'service_item_categories.name',
				DB::raw('COUNT(service_item_sub_categories.id) as sub_category'),
				DB::raw('IF((service_item_categories.deleted_at) IS NULL ,"Active","Inactive") as status')
			)
			->leftJoin('service_item_sub_categories', 'service_item_categories.id', 'service_item_sub_categories.category_id')
			->where('service_item_categories.company_id', Auth::user()->company_id)
			->groupBy('service_item_categories.id')
			->orderBy('service_item_categories.id', 'Desc')
		// ->get()
		;
		// dd($service_item_category_list);

		return Datatables::of($service_item_category_list)
			->addColumn('child_checkbox', function ($service_item_category_list) {
				$checkbox = "<td><div class='table-checkbox'><input type='checkbox' id='child_" . $service_item_category_list->id . "' /><label for='child_" . $service_item_category_list->id . "'></label></div></td>";

				return $checkbox;
			})
			->addColumn('name', function ($service_item_category_list) {
				$status = $service_item_category_list->status == 'Active' ? 'green' : 'red';
				return '<span class="status-indicator ' . $status . '"></span>' . $service_item_category_list->name;
			})
			->addColumn('action', function ($service_item_category_list) {
				$edit_img = asset('public/theme/img/table/cndn/edit.svg');
				$delete_img = asset('public/theme/img/table/cndn/delete.svg');

				return '<a href="#!/service-invoice-pkg/service-item-category/edit/' . $service_item_category_list->id . '" class="">
                        <img class="img-responsive" src="' . $edit_img . '" alt="Edit" />
                    	</a>
						<a href="javascript:;" data-toggle="modal" data-target="#delete_service_item_category"
					onclick="angular.element(this).scope().deleteServiceItemCategory(' . $service_item_category_list->id . ')" dusk = "delete-btn" title="Delete">
					<img src="' . $delete_img . '" alt="delete" class="img-responsive">
					</a>';
			})
			->make(true);
	}

	public function getServiceItemCategoryFormData($id = NULL) {
		//dd('in');

		if (!$id) {
			$service_item_category = new ServiceItemCategory;
			$this->data['action'] = 'Add';
		} else {
			//dd('in');
			$this->data['action'] = 'Edit';
			$service_item_category = ServiceItemCategory::withTrashed()->with([
				'subCategory',
				'subCategory.attachment',
				//'serviceItemSubCategory',
			])->find($id);
			//dd($service_item_category);
			if (!$service_item_category) {
				return response()->json(['success' => false, 'error' => 'Service Item Category not found']);
			}
		}
		//dd($service_item_category);
		$this->data['service_item_category'] = $service_item_category;
		$this->data['success'] = true;
		//dd($this->data);
		return response()->json($this->data);
	}

	public function saveServiceItemCategory(Request $request) {
		// dd($request->all());
		DB::beginTransaction();
		try {

			$error_messages = [
				'name.required' => 'Service item category name is required',
				'name.unique' => 'Service item category name has already been taken',
			];

			$validator = Validator::make($request->all(), [
				'name' => [
					'unique:service_item_categories,name,' . $request->id . ',id,company_id,' . Auth::user()->company_id,
					'required:true',
				],
			], $error_messages);

			if ($validator->fails()) {
				return response()->json(['success' => false, 'errors' => $validator->errors()->all()]);
			}

			//VALIDATE FIELD-GROUP FIELD UNIQUE
			if ($request->sub_category && !empty($request->sub_category)) {
				$field_group_fields = collect($request->sub_category)->pluck('name')->toArray();
				$field_group_fields_unique = array_unique($field_group_fields);
				if (count($field_group_fields) != count($field_group_fields_unique)) {
					return response()->json(['success' => false, 'errors' => ['Service item Sub category has already been taken']]);
				}
			}

			if ($request->id) {
				$service_item_category = ServiceItemCategory::withTrashed()->find($request->id);
				$service_item_category->updated_at = date("Y-m-d H:i:s");
				$service_item_category->updated_by_id = Auth()->user()->id;
			} else {
				$service_item_category = new ServiceItemCategory();
				$service_item_category->created_at = date("Y-m-d H:i:s");
				$service_item_category->created_by_id = Auth()->user()->id;
			}

			if ($request->status == 'Inactive') {
				$service_item_category->deleted_at = date("Y-m-d H:i:s");
				$service_item_category->deleted_by_id = Auth()->user()->id;
			} else {
				$service_item_category->deleted_at = NULL;
				$service_item_category->deleted_by_id = NULL;
			}
			$service_item_category->fill($request->all());
			$service_item_category->name = $request->name;
			$service_item_category->company_id = Auth::user()->company_id;
			$service_item_category->save();

			//SAVE FIELD-GROUP FIELD
			//$field_group->fields()->sync([]);
			if ($request->sub_category) {
				if (!empty($request->sub_category)) {
					$i = 1;
					foreach ($request->sub_category as $key => $sub_category) {
						if (isset($sub_category['id'])) {
							$sub_service_item_category = ServiceItemSubCategory::withTrashed()->find($sub_category['id']);
							$sub_service_item_category->updated_at = date("Y-m-d H:i:s");
							$sub_service_item_category->updated_by_id = Auth()->user()->id;

						} else {
							$sub_service_item_category = new ServiceItemSubCategory();
							$sub_service_item_category->created_at = date("Y-m-d H:i:s");
							$sub_service_item_category->created_by_id = Auth()->user()->id;
						}

						$sub_service_item_category->category_id = $service_item_category->id;
						$sub_service_item_category->company_id = Auth()->user()->company_id;
						$sub_service_item_category->name = $sub_category['name'];
						if ($sub_category['status'] == 'Inactive') {
							$sub_service_item_category->deleted_at = date("Y-m-d H:i:s");
							$sub_service_item_category->deleted_by_id = Auth()->user()->id;
						} else {
							$sub_service_item_category->deleted_at = NULL;
							$sub_service_item_category->deleted_by_id = NULL;
						}
						$sub_service_item_category->save();
						// dd($sub_service_item_category->id);
						//CREATE DIRECTORY TO STORAGE PATH
						$attachment_path = storage_path('app/public/service-invoice/service-item-sub-category/attachments/');
						Storage::makeDirectory($attachment_path, 0777);

						//SAVE Job Card ATTACHMENT
						if (!empty($sub_category['additional_image'])) {
							$remove_previous_attachments = Attachment::where([
								'entity_id' => $sub_service_item_category->id,
								'attachment_of_id' => 11340,
								'attachment_type_id' => 11341,
							])->get();
							if (!empty($remove_previous_attachments)) {
								foreach ($remove_previous_attachments as $key => $remove_previous_attachment) {
									$img_path = $attachment_path . $remove_previous_attachment->name;
									if (File::exists($img_path)) {
										File::delete($img_path);
									}
									$remove = $remove_previous_attachment->forceDelete();
								}
							}
							$file_name_with_extension = $sub_category['additional_image']->getClientOriginalName();
							$file_name = pathinfo($file_name_with_extension, PATHINFO_FILENAME);
							$extension = $sub_category['additional_image']->getClientOriginalExtension();

							$name = $sub_service_item_category->id . '_' . $file_name . '.' . $extension;
							$name = str_replace(' ', '-', $name); // Replaces all spaces with hyphens.

							$sub_category['additional_image']->move(storage_path('app/public/service-invoice/service-item-sub-category/attachments/'), $name);

							$attachement = new Attachment;
							$attachement->attachment_of_id = 11340;
							$attachement->attachment_type_id = 11341;
							$attachement->entity_id = $sub_service_item_category->id;
							$attachement->name = $name;
							$attachement->save();

							$sub_service_item_category->additional_image_id = $attachement->id;
							$sub_service_item_category->save();
						}
					}
				}
			}

			if (!empty($request->sub_category_removal_id)) {
				$sub_category_removal_id = json_decode($request->sub_category_removal_id, true);
				ServiceItemSubCategory::whereIn('id', $sub_category_removal_id)->forceDelete();
			}
			if ($request->id) {
				$message = 'Service item category updated successfully';
			} else {
				$message = 'Service item category saved successfully';
			}
			DB::commit();
			return response()->json(['success' => true, 'message' => $message]);
		} catch (Exception $e) {
			DB::rollBack();
			// dd($e->getMessage());
			return response()->json(['success' => false, 'errors' => ['Exception Error' => $e->getMessage()]]);
		}
	}
	public function serviceItemCategoryDelete($id) {
		$service_item_category = ServiceItemCategory::withTrashed()->find($id);
		if ($service_item_category) {
			$service_item_category->forceDelete();
			return response()->json(['success' => true, 'message' => 'Service item category deleted successfully']);
		}

	}

}
