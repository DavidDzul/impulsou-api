<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAdvancePaymentEligibleToScholarshipProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->boolean('advance_payment_eligible')->default(false)->after('monto_apoyo');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->dropColumn('advance_payment_eligible');
        });
    }
}
