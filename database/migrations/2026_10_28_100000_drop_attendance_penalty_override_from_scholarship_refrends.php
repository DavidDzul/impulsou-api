<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops attendance_penalty_override. Its only writer (ClearAttendancePenaltyAction)
 * was never wired to a route and nothing ever read the flag; forgiving
 * attendance penalties is done by ApproveFullPaymentAction, which moves the
 * refrend out of DRAFT so fullRecalculate() can no longer re-apply them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn('attendance_penalty_override');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->boolean('attendance_penalty_override')
                  ->default(false)
                  ->after('attendance_summary_snapshot')
                  ->comment('When true, automatic attendance penalties are not re-applied on recalculate.');
        });
    }
};
