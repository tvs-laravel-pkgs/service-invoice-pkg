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
			3024 => [
				'display_order' => 4,
				'parent_id' => 3020,
				'name' => 'export-service-item',
				'display_name' => 'Export',
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

			3044 => [
				'display_order' => 3,
				'parent_id' => 3040,
				'name' => 'create-service-invoice-for-all-outlets',
				'display_name' => 'Created for All Outlets',
			],
			3045 => [
				'display_order' => 4,
				'parent_id' => 3040,
				'name' => 'create-cn',
				'display_name' => 'Create CN',
			],
			3046 => [
				'display_order' => 5,
				'parent_id' => 3040,
				'name' => 'create-dn',
				'display_name' => 'Create DN',
			],
			3047 => [
				'display_order' => 6,
				'parent_id' => 3040,
				'name' => 'view-all-cn-dn',
				'display_name' => 'View All',
			],
			3048 => [
				'display_order' => 7,
				'parent_id' => 3040,
				'name' => 'view-own-cn-dn',
				'display_name' => 'View Own Only',
			],
			3049 => [
				'display_order' => 8,
				'parent_id' => 3040,
				'name' => 'view-outlet-based-cn-dn',
				'display_name' => 'View Outlet Based',
			],
			3050 => [
				'display_order' => 9,
				'parent_id' => 3040,
				'name' => 'import-cn-dn',
				'display_name' => 'Import',
			],
			3051 => [
				'display_order' => 10,
				'parent_id' => 3040,
				'name' => 'create-inv',
				'display_name' => 'Create INV',
			],
			3052 => [
				'display_order' => 11,
				'parent_id' => 3040,
				'name' => 'view-sbu-based-cn-dn',
				'display_name' => 'View SBU Based',
			],

			3053 => [
				'display_order' => 13,
				'parent_id' => 840,
				'name' => 'cndn-reports',
				'display_name' => 'CNDN Reports',
			],

			3054 => [
				'display_order' => 1,
				// 'display_order' => 12,
				'parent_id' => 3053,
				'name' => 'tcs-export-own',
				'display_name' => 'TCS Export Own',
			],
			3055 => [
				'display_order' => 2,
				// 'display_order' => 13,
				'parent_id' => 3053,
				'name' => 'tcs-export-all',
				'display_name' => 'TCS Export All',
			],
			3056 => [
				'display_order' => 3,
				// 'display_order' => 14,
				'parent_id' => 3053,
				'name' => 'tcs-export-outlet-based',
				'display_name' => 'TCS Export outlet-based',
			],

			3057 => [
				'display_order' => 4,
				// 'display_order' => 15,
				'parent_id' => 3053,
				'name' => 'gst-export',
				'display_name' => 'GST Export',
			],

			3058 => [
				// 'display_order' => 5,
				'display_order' => 14,
				'parent_id' => 3040,
				'name' => 'service-invoice-irn-cancel',
				'display_name' => 'Service Invoice IRN Cancelation',
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