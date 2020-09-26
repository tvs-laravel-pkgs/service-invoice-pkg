<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoiceU22092020 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->text('errors')->nullable()->after('final_amount');

			$table->text('cancel_irn_request')->nullable()->after('irn_response');
			$table->text('cancel_irn_response')->nullable()->after('cancel_irn_request');
			$table->string('cancel_irn_number', 191)->nullable()->after('cancel_irn_response');
			$table->datetime('cancel_irn_date')->nullable()->after('cancel_irn_number');

		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->dropColumn('errors');

			$table->dropColumn('cancel_irn_request');
			$table->dropColumn('cancel_irn_response');
			$table->dropColumn('cancel_irn_number');
			$table->dropColumn('cancel_irn_date');
		});
	}
}
