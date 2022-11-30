<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddShipAddressInServiceInvoice extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('service_invoices', 'ship_address_id')) {
            Schema::table('service_invoices', function (Blueprint $table) {
                $table->unsignedInteger('ship_address_id')->nullable()->after('address_id');

                $table->foreign('ship_address_id', 'service_invoice_ship_foreign')->references('id')->on('addresses')->onDelete('cascade')->onUpdate('cascade');
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
        $doctrineTable = Schema::getConnection()->getDoctrineSchemaManager()->listTableDetails('service_invoices');
        if (Schema::hasColumn('service_invoices', 'ship_address_id')) {
            Schema::table('service_invoices', function (Blueprint $table) use ($doctrineTable) {
                if ($doctrineTable->hasForeignKey('service_invoice_ship_foreign'))
                    $table->dropForeign('service_invoice_ship_foreign');
                $table->dropColumn('ship_address_id');
            });
        }
    }
}
