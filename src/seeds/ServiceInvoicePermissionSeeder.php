<?php
namespace Abs\ServiceInvoicePkg\Database\Seeds;

use App\Permission;
use Illuminate\Database\Seeder;

class ServiceInvoicePermissionSeeder extends Seeder {
	/**
	 * Run the database seeds.
	 *
	 * @return void
	 */
	public function run() {
		$permissions = [
			//MASTER > SERVICE ITEM CATEGORIES
			3000 => [
				'display_order' => 10,
				'parent_id' => 2,
				'name' => 'service-item-categories',
				'display_name' => 'Service Item Categories',
			],
			3001 => [
				'display_order' => 1,
				'parent_id' => 3000,
				'name' => 'add-service-item-category',
				'display_name' => 'Add',
			],
			3002 => [
				'display_order' => 2,
				'parent_id' => 3000,
				'name' => 'edit-service-item-category',
				'display_name' => 'Edit',
			],
			3003 => [
				'display_order' => 3,
				'parent_id' => 3000,
				'name' => 'delete-service-item-category',
				'display_name' => 'Delete',
			],

			//MASTER > SERVICE ITEMS
			3020 => [
				'display_order' => 11,
				'parent_id' => 2,
				'name' => 'service-items',
				'display_name' => 'Service Items',
			],
			3021 => [
				'display_order' => 1,
				'parent_id' => 3020,
				'name' => 'add-service-item',
				'display_name' => 'Add',
			],
			3022 => [
				'display_order' => 2,
				'parent_id' => 3020,
				'name' => 'edit-service-item',
				'display_name' => 'Edit',
			],
			3023 => [
				'display_order' => 3,
				'parent_id' => 3020,
				'name' => 'delete-service-item',
				'display_name' => 'Delete',
			],

			//MASTER > SERVICE INVOICE
			3040 => [
				'display_order' => 12,
				'parent_id' => NULL,
				'name' => 'service-invoices',
				'display_name' => 'Service Invoices',
			],
			3041 => [
				'display_order' => 1,
				'parent_id' => 3040,
				'name' => 'add-service-invoice',
				'display_name' => 'Add',
			],
			3042 => [
				'display_order' => 2,
				'parent_id' => 3040,
				'name' => 'edit-service-invoice',
				'display_name' => 'Edit',
			],
			3043 => [
				'display_order' => 3,
				'parent_id' => 3040,
				'name' => 'delete-service-invoice',
				'display_name' => 'Delete',
			],

		];

		foreach ($permissions as $permission_id => $permsion) {
			$permission = Permission::firstOrNew([
				'id' => $permission_id,
			]);
			$permission->fill($permsion);
			$permission->save();
		}
		//$this->call(RoleSeeder::class);

	}
}