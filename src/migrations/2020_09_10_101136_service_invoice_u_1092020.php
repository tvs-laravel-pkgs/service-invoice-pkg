<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoiceU1092020 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->unsignedInteger('sub_category_id')->nullable()->change();

			$table->unsignedInteger('category_id')->nullable()->after('sbu_id');

			$table->foreign('category_id')->references('id')->on('service_item_categories')->onDelete('CASCADE')->onUpdate('cascade');

		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->dropForeign('service_invoices_category_id_foreign');
			$table->dropColumn('category_id');
		});
	}
}
