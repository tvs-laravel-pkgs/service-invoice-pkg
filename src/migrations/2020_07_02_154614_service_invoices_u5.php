<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ServiceInvoicesU5 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            $table->unsignedinteger('config_id')->nullable()->after('reference')->comment('To identify  project request created is Service CN or not');
            $table->foreign('config_id')->references('id')->on('configs')->onDelete('CASCADE')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
       Schema::table('service_invoices', function (Blueprint $table) {
            $table->dropColumn('config_id');
            $table->dropForeign('service_invoices_config_id_foreign');
        });
    }
}
