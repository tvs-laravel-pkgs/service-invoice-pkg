<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddInvoicePecentageInServiceInvoice extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('honda_service_invoices')) {
            Schema::table('honda_service_invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('honda_service_invoices', 'invoice_amount'))
                    $table->decimal('invoice_amount', 12, 2)->after('invoice_number')->nullable();
            });
        }
        if (Schema::hasTable('honda_service_invoice_items')) {
            Schema::table('honda_service_invoice_items', function (Blueprint $table) {
                if (!Schema::hasColumn('honda_service_invoice_items', 'tcs_percentage'))
                    $table->decimal('tcs_percentage', 12, 2)->after('description')->nullable();
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
        if (Schema::hasTable('honda_service_invoices')) {
            Schema::table('honda_service_invoices', function (Blueprint $table) {
                if (Schema::hasColumn('honda_service_invoices', 'invoice_amount'))
                    $table->dropColumn('invoice_amount');
            });
        }
        if (Schema::hasTable('honda_service_invoice_items')) {
            Schema::table('honda_service_invoice_items', function (Blueprint $table) {
                if (Schema::hasColumn('honda_service_invoice_items', 'tcs_percentage'))
                    $table->dropColumn('tcs_percentage');
            });
        }
    }
}
