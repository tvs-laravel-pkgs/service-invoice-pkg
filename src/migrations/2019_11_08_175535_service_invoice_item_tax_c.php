<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoiceItemTaxC extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::create('service_invoice_item_tax', function (Blueprint $table) {
			$table->unsignedInteger('service_invoice_item_id');
			$table->unsignedInteger('tax_id');
			$table->unsignedDecimal('percentage', 5, 2);
			$table->unsignedDecimal('amount', 12, 2);

			$table->foreign('service_invoice_item_id', 'siitsii')->references('id')->on('service_invoice_items')->onDelete('CASCADE')->onUpdate('cascade');
			$table->foreign('tax_id', 'siitti')->references('id')->on('taxes')->onDelete('CASCADE')->onUpdate('cascade');

			$table->unique(["service_invoice_item_id", "tax_id"], 'siit_unique');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::dropIfExists('service_invoice_item_tax');
	}
}
