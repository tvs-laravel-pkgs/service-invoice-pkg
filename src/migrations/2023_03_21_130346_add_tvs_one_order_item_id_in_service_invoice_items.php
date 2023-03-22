<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddTvsOneOrderItemIdInServiceInvoiceItems extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('service_invoice_items', function (Blueprint $table) {
            $table->unsignedInteger('tvsone_order_item_id')->nullable()->after('e_invoice_uom_id');
            $table->foreign('tvsone_order_item_id', 'service_inv_item_tvsone_order_item_foreign')->references('id')->on('tvs_one_order_items')->onDelete('SET NULL')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('service_invoice_items', function (Blueprint $table) {
            $table->dropForeign('service_inv_item_tvsone_order_item_foreign');
            $table->dropColumn('tvsone_order_item_id');
        });
    }
}
