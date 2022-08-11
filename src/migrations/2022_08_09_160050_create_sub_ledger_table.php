<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSubLedgerTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sub_ledger', function (Blueprint $table) {
            $table->increments('id');
			$table->unsignedInteger('company_id');
			$table->unsignedInteger('coa_code_id')->nullable();
			$table->string('ax_subgl', 191);
			$table->string('ax_subgldesc', 191);
			$table->unsignedInteger('created_by_id')->nullable();
			$table->unsignedInteger('updated_by_id')->nullable();
			$table->unsignedInteger('deleted_by_id')->nullable();
			$table->timestamps();
			$table->softDeletes();

			$table->foreign('company_id')->references('id')->on('companies')->onDelete('CASCADE')->onUpdate('cascade');
			$table->foreign('coa_code_id')->references('id')->on('coa_codes')->onDelete('CASCADE')->onUpdate('cascade');

			$table->foreign('created_by_id')->references('id')->on('users')->onDelete('SET NULL')->onUpdate('cascade');
			$table->foreign('updated_by_id')->references('id')->on('users')->onDelete('SET NULL')->onUpdate('cascade');
			$table->foreign('deleted_by_id')->references('id')->on('users')->onDelete('SET NULL')->onUpdate('cascade');

			$table->unique(["company_id", "ax_subgl"]);
        });
        Schema::table('service_items', function (Blueprint $table) {
            $table->tinyInteger('sub_ledger_id')->after('is_tcs')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sub_ledger');
        Schema::table('service_items', function (Blueprint $table) {
            $table->dropColumn('sub_ledger_id');
        });
    }
}
