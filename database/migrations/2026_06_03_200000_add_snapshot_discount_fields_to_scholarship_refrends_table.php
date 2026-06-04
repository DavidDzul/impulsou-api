<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSnapshotDiscountFieldsToScholarshipRefrendsTable extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->decimal('snapshot_discount_percentage', 5, 2)->nullable()->after('base_amount');
            $table->string('snapshot_discount_reason', 200)->nullable()->after('snapshot_discount_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn(['snapshot_discount_percentage', 'snapshot_discount_reason']);
        });
    }
}
