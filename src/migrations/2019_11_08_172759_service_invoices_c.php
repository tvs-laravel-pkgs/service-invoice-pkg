<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ServiceInvoicesC extends Migration {
	/**
	 * Run the migrations.
	 *
	 * @return void
	 */
	public function up() {
		Schema::create('service_invoices', function (Blueprint $table) {
			$table->increments('id');
			$table->unsignedInteger('company_id');
			$table->string('number', 191);
			$table->unsignedInteger('branch_id');
			$table->unsignedInteger('sbu_id');
			$table->unsignedInteger('sub_category_id');
			$table->datetime('invoice_date');
			$table->datetime('document_date');
			$table->unsignedInteger('customer_id');
			$table->unsignedInteger('items_count');
			$table->unsignedDecimal('amount_total', 12, 2);
			$table->unsignedDecimal('tax_total', 12, 2);
			$table->unsignedDecimal('sub_total', 12, 2);
			$table->unsignedDecimal('total', 12, 2);
			$table->unsignedInteger('created_by_id')->nullable();
			$table->unsignedInteger('updated_by_id')->nullable();
			$table->unsignedInteger('deleted_by_id')->nullable();
			$table->timestamps();
			$table->softDeletes();

			$table->foreign('company_id')->references('id')->on('companies')->onDelete('CASCADE')->onUpdate('cascade');
			$table->foreign('branch_id')->references('id')->on('outlets')->onDelete('CASCADE')->onUpdate('cascade');
			// $table->foreign('sbu_id')->references('id')->on('sbus')->onDelete('CASCADE')->onUpdate('cascade');
			$table->foreign('sub_category_id')->references('id')->on('service_item_sub_categories')->onDelete('CASCADE')->onUpdate('cascade');
			$table->foreign('customer_id')->references('id')->on('customers')->onDelete('CASCADE')->onUpdate('cascade');
			$table->foreign('created_by_id')->references('id')->on('users')->onDelete('SET NULL')->onUpdate('cascade');
			$table->foreign('updated_by_id')->references('id')->on('users')->onDelete('SET NULL')->onUpdate('cascade');
			$table->foreign('deleted_by_id')->references('id')->on('users')->onDelete('SET NULL')->onUpdate('cascade');

			$table->unique(["company_id", "number"]);
		});
	}

	/**
	 * Reverse the migrations.
	 *
	 * @return void
	 */
	public function down() {
		//
	}
}
