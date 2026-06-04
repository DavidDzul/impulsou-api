<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDiscountReasonToScholarshipProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->string('discount_reason', 200)->nullable()->after('active_discount_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->dropColumn('discount_reason');
        });
    }
}
