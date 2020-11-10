<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceItemSubCategoryU101020 extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::table('service_item_sub_categories', function (Blueprint $table) {
			$table->unsignedInteger('additional_image_id')->nullable()->after('name');
			$table->foreign('additional_image_id')->references('id')->on('attachments')->onDelete('SET NULL')->onUpdate('cascade');

		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::table('service_item_sub_categories', function (Blueprint $table) {
			$table->dropForeign('service_item_sub_categories_additional_image_id_foreign');

			$table->dropColumn('additional_image_id');
		});
	}
}
