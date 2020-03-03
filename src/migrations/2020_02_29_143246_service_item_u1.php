<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceItemU1 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_items', function (Blueprint $table) {
			$table->string('default_reference', 255)->nullable()->after('sac_code_id');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_items', function (Blueprint $table) {
			$table->dropColumn('default_reference');
		});
	}
}
