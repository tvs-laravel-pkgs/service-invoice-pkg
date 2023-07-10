<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateIsDiscountInServiceInvoice extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('service_items', 'is_discount')) {
            Schema::table('service_items', function (Blueprint $table) {
                $table->tinyInteger('is_discount')->default(0)->after('sub_ledger_id');
            });
        }
        if (!Schema::hasColumn('service_invoice_items', 'is_discount')) {
            Schema::table('service_invoice_items', function (Blueprint $table) {
                $table->tinyInteger('is_discount')->default(0)->after('sub_total');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('service_invoice_items', 'is_discount')) {
            Schema::table('service_invoice_items', function (Blueprint $table) {
                $table->dropColumn('is_discount');
            });
        }
        if (Schema::hasColumn('service_items', 'is_discount')) {
            Schema::table('service_items', function (Blueprint $table) {
                $table->dropColumn('is_discount');
            });
        }
    }
}
