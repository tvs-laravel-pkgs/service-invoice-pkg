<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoicesU3 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->unsignedInteger('type_id')->nullable()->after('number');
			$table->foreign('type_id')->references('id')->on('configs')->onDelete('SET NULL')->onUpdate('cascade');
			$table->boolean('is_cn_created')->nullable()->after('comments');

		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_invoices', function (Blueprint $table) {
			$table->dropForeign('service_invoices_type_id_foreign');

			$table->dropColumn('type_id');
			$table->dropColumn('is_cn_created');
		});
	}
}
