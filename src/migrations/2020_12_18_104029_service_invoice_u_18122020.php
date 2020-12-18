<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoiceU18122020 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->text('json_request_send_to_bdo_api')->nullable()->after('is_service');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->dropColumn('json_request_send_to_bdo_api');
		});
	}
}
