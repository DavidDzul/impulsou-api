<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemovePaymentDatesFromScholarshipProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->dropColumn(['payment_start_date', 'payment_end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->date('payment_start_date')->after('monthly_amount')->nullable();
            $table->date('payment_end_date')->after('payment_start_date')->nullable();
        });
    }
}
