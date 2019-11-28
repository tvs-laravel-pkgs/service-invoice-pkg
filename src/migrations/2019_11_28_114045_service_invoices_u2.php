<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoicesU2 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->unsignedInteger('status_id')->nullable()->after('total');
			$table->string('comments')->nullable()->after('status_id');
			$table->foreign('status_id')->references('id')->on('approval_type_statuses')->onDelete('SET NULL')->onUpdate('cascade');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->dropForeign('service_invoices_status_id_foreign');
			$table->dropColumn('status_id');
			$table->dropColumn('comments');
		});
	}
}
