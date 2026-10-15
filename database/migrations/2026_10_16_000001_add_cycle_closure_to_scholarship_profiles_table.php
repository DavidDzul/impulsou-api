<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/egresado-baja-definitiva, design D1 — the stop marker. Nullable and
 * additive, reversible via down(). No backfill: existing EGRESADO/
 * BAJA_DEFINITIVA rows keep whatever state they already have; the local DB
 * will be reset (see design "Migration / Rollout" — no production data).
 */
class AddCycleClosureToScholarshipProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->date('cycle_closed_period')->nullable()->after('advance_payment_eligible');
            $table->string('cycle_closure_reason', 30)->nullable()->after('cycle_closed_period');
            $table->unsignedBigInteger('cycle_closed_refrend_id')->nullable()->after('cycle_closure_reason');

            $table->foreign('cycle_closed_refrend_id')
                ->references('id')->on('scholarship_refrends')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->dropForeign(['cycle_closed_refrend_id']);
            $table->dropColumn(['cycle_closed_period', 'cycle_closure_reason', 'cycle_closed_refrend_id']);
        });
    }
}
