<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoiceU17122020 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_items', function (Blueprint $table) {
			$table->unsignedDecimal('cess_on_gst_percentage', 5, 2)->nullable()->after('tcs_percentage');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_items', function (Blueprint $table) {
			$table->dropColumn('cess_on_gst_percentage');
		});
	}
}
