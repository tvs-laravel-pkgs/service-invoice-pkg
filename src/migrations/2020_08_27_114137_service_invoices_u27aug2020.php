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

			$table->dropForeign('service_invoices_customer_id_foreign');

			$table->unsignedInteger('to_account_type_id')->nullable()->after('document_date');

			$table->datetime('invoice_date')->nullable()->change();
			$table->boolean('is_reverse_charge_applicable')->nullable()->after('customer_id');
			$table->string('po_reference_number', 191)->nullable()->after('is_reverse_charge_applicable');
			$table->string('invoice_number', 191)->nullable()->after('po_reference_number');
			$table->unsignedDecimal('round_off_amount', 12, 2)->nullable()->after('total');
			$table->unsignedDecimal('final_amount', 12, 2)->nullable()->after('round_off_amount');

			$table->string('irn_number', 191)->nullable()->after('final_amount');
			$table->string('qr_image', 191)->nullable()->after('irn_number');
			$table->text('irn_request')->nullable()->after('qr_image');
			$table->text('irn_response')->nullable()->after('irn_request');

			$table->foreign('to_account_type_id')->references('id')->on('configs')->onDelete('CASCADE')->onUpdate('cascade');

		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->dropColumn('is_reverse_charge_applicable');
			$table->dropColumn('po_reference_number');
			$table->dropColumn('invoice_number');
			$table->dropColumn('round_off_amount');
			$table->dropColumn('final_amount');
			$table->dropColumn('irn_number');
			$table->dropColumn('qr_image');
			$table->dropColumn('irn_request');
			$table->dropColumn('irn_response');
		});
	}
}
