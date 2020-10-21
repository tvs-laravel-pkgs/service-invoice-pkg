<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoiceItemU21102020 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_invoice_items', function (Blueprint $table) {
			$table->dropForeign('service_invoice_items_service_item_id_foreign');
			$table->dropForeign('service_invoice_items_service_invoice_id_foreign');

			$table->dropUnique('service_invoice_items_service_invoice_id_service_item_id_unique');

			$table->foreign('service_invoice_id')->references('id')->on('service_invoices')->onDelete('CASCADE')->onUpdate('cascade');
			$table->foreign('service_item_id')->references('id')->on('service_items')->onDelete('CASCADE')->onUpdate('cascade');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoice_items', function (Blueprint $table) {
			$table->unique(["service_invoice_id", "service_item_id"]);
		});
	}
}
