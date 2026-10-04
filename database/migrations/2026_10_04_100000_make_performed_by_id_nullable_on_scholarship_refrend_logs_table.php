<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/egresado-status-timing, design D4.
 *
 * `performed_by_id` becomes nullable so the automatic month+2 egreso path
 * (GraduateBecarioAction, invoked with no authenticated user) can write a
 * ScholarshipRefrendLog row without an attributed staff member. FK is
 * retained — doctrine/dbal ^3.1.4 (installed) makes `->change()` emit
 * `MODIFY performed_by_id BIGINT UNSIGNED NULL` on MySQL, which preserves
 * the constraint. Exact precedent:
 * 2026_09_27_100000_default_monthly_amount_on_scholarship_profiles_table.php
 * (same nullable()->change() / nullable(false)->change() up/down pair, no
 * driver branch). Column type is redeclared as unsignedBigInteger — what
 * foreignId() created at 2026_05_13_100600:20 — never foreignId() itself,
 * which is invalid inside a ->change() call.
 */
class MakePerformedByIdNullableOnScholarshipRefrendLogsTable extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrend_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('performed_by_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Design R5: down() cannot restore NOT NULL while system-triggered
        // rows (performed_by_id IS NULL) exist — delete them BEFORE
        // reapplying the constraint, or the ->change() below fails/corrupts
        // data on MySQL.
        DB::table('scholarship_refrend_logs')->whereNull('performed_by_id')->delete();

        Schema::table('scholarship_refrend_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('performed_by_id')->nullable(false)->change();
        });
    }
}
