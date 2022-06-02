<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddDiscountAvailInServiceInvoice extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('service_invoices', 'is_discount_avail')) {
                $table->tinyInteger('is_discount_avail')->default('0')->after('reference');
            }
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
            if (Schema::hasColumn('service_invoices', 'is_discount_avail')) {
                $table->dropColumn('is_discount_avail');
            }
        });
    }
}
