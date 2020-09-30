<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterServiceInvoicesU2992020 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->unsignedInteger('address_id')->nullable()->after('customer_id');

			$table->foreign('address_id')->references('id')->on('addresses')->onDelete('SET NULL')->onUpdate('cascade');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->dropForeign('service_invoices_address_id_foreign');

			$table->dropColumn('address_id');
		});
	}
}
