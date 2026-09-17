<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropCarryoverPercentageFromScholarshipRefrends extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('scholarship_refrends', 'carryover_percentage')) {
            Schema::table('scholarship_refrends', function (Blueprint $table) {
                $table->dropColumn('carryover_percentage');
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
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->decimal('carryover_percentage', 5, 2)->nullable()->after('carryover_months_detail');
        });
    }
}
