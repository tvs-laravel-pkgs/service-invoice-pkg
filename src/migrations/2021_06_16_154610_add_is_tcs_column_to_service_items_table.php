<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddIsTcsColumnToServiceItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('service_items', function (Blueprint $table) {
            $table->tinyInteger('is_tcs')->after('default_reference')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('service_items', function (Blueprint $table) {
            $table->dropColumn('is_tcs');
        });
    }
}
