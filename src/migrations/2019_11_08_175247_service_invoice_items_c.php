<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoiceItemsC extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::create('service_invoice_items', function (Blueprint $table) {
			$table->increments('id');
			$table->unsignedInteger('service_invoice_id');
			$table->unsignedInteger('service_item_id');
			$table->string('description', 255)->nullable();
			$table->unsignedDecimal('qty', 12, 2);
			$table->unsignedDecimal('rate', 12, 2);
			$table->unsignedDecimal('sub_total', 12, 2);

			$table->foreign('service_invoice_id')->references('id')->on('service_invoices')->onDelete('CASCADE')->onUpdate('cascade');
			$table->foreign('service_item_id')->references('id')->on('service_items')->onDelete('CASCADE')->onUpdate('cascade');

			$table->unique(["service_invoice_id", "service_item_id"]);
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::dropIfExists('service_invoice_items');
	}
}
