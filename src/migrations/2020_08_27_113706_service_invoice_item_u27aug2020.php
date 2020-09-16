@@ -1,31 +0,0 @@
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoiceItemU27aug2020 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_invoice_items', function (Blueprint $table) {
			$table->unsignedInteger('e_invoice_uom_id')->nullable()->after('service_item_id');
			$table->foreign('e_invoice_uom_id')->references('id')->on('e_invoice_uoms')->onDelete('SET NULL')->onUpdate('cascade');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoice_items', function (Blueprint $table) {
			$table->dropForeign('service_invoice_items_e_invoice_uom_id_foreign');
			$table->dropColumn('e_invoice_uom_id');
		});
	}
}