<?php

namespace Abs\ServiceInvoicePkg;

use App\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceItemSubCategory extends Model {
	use SoftDeletes;
	protected $table = 'service_item_sub_categories';
	protected $fillable = [
		'created_by_id',
		'updated_by_id',
		'deleted_by_id',
	];

	public function serviceItemCategory() {
		return $this->belongsTo('Abs\AttributePkg\ServiceItemCategory', 'category_id', 'id');
	}

	public static function createFromObject($record_data) {

		$errors = [];
		$company = Company::where('code', $record_data->company)->first();
		if (!$company) {
			dump('Invalid Company : ' . $record_data->company);
			return;
		}

		$admin = $company->admin();
		if (!$admin) {
			dump('Default Admin user not found');
			return;
		}

		$category = ServiceItemCategory::where('name', $record_data->category)->where('company_id', $company->id)->first();
		if (!$category) {
			$errors[] = 'Invalid category : ' . $record_data->category;
		}

		if (count($errors) > 0) {
			dump($errors);
			return;
		}

		$record = self::firstOrNew([
			'company_id' => $company->id,
			'name' => $record_data->sub_category_name,
		]);
		$record->category_id = $category->id;
		$record->created_by_id = $admin->id;
		$record->save();
		return $record;
	}

	public static function createFromCollection($records) {
		foreach ($records as $key => $record_data) {
			try {
				if (!$record_data->company) {
					continue;
				}
				$record = self::createFromObject($record_data);
			} catch (Exception $e) {
				dd($e);
			}
		}
	}
}
