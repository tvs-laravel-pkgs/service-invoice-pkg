<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoicesU27aug2020 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->boolean('is_e_reverse_charge_applicable')->nullable()->after('customer_id');
			$table->string('e_po_reference_number', 191)->nullable()->after('is_e_reverse_charge_applicable');
			$table->string('e_invoice_number', 191)->nullable()->after('e_po_reference_number');
			$table->date('e_invoice_date')->nullable()->after('e_invoice_number');
			$table->unsignedDecimal('e_round_off_amount', 12, 2)->nullable()->after('total');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->dropColumn('is_e_reverse_charge_applicable');
			$table->dropColumn('e_po_reference_number');
			$table->dropColumn('e_invoice_number');
			$table->dropColumn('e_round_off_amount');
			$table->dropColumn('e_invoice_date');
		});
	}
}
