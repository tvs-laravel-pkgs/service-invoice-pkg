<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceItemFieldGroupC extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::create('service_item_field_group', function (Blueprint $table) {
			$table->unsignedInteger('service_item_id');
			$table->unsignedInteger('field_group_id');

			$table->foreign('service_item_id')->references('id')->on('service_items')->onDelete('CASCADE')->onUpdate('cascade');
			$table->foreign('field_group_id')->references('id')->on('field_groups')->onDelete('CASCADE')->onUpdate('cascade');

			$table->unique(["service_item_id", "field_group_id"], 'service_item_field_group_unique');
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		Schema::dropIfExists('service_item_field_group');
	}
}
