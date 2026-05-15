<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterAttendancesAddLatePenaltyColumns extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('late_penalty_consumed')->default(false)->after('observations');
            $table->foreignId('late_penalty_consumed_refrend_id')
                ->nullable()
                ->after('late_penalty_consumed')
                ->constrained('scholarship_refrends')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['late_penalty_consumed_refrend_id']);
            $table->dropColumn(['late_penalty_consumed', 'late_penalty_consumed_refrend_id']);
        });
    }
}
