<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/scholarship-telmex-iu-split, design D7.
 *
 * Only `scholarship_profiles.scholarship_type` is a real MySQL ENUM (design
 * verification correction — `scholarship_refrends.snapshot_scholarship_type`
 * is a plain `string()` column, not an enum, so it needs no ALTER here). The
 * ENUM ALTER is MySQL-only (skipped on the sqlite test driver, matching the
 * existing `2026_05_19_100200_...` precedent) — the PHP enum case is still
 * mandatory regardless of driver because `ScholarshipRefrend::snapshot_scholarship_type`
 * is enum-cast on the model.
 *
 * Local/dev only: no backward-compat data migration for already-generated
 * TELMEX refrend rows (user-confirmed acceptable to reset).
 */
class AddTelmexIuScholarshipType extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE scholarship_profiles
                MODIFY COLUMN scholarship_type
                ENUM('IU','TELMEX','TELMEX_IU') NOT NULL DEFAULT 'IU'
            ");
        }

        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->decimal('iu_payment_amount', 8, 2)->nullable()->after('monto_apoyo');
        });

        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->decimal('snapshot_telmex_covered_amount', 8, 2)->nullable()->after('snapshot_monto_apoyo');
        });

        Schema::create('scholarship_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('telmex_base_amount', 8, 2)->default(0);
            $table->timestamps();
        });

        DB::table('scholarship_settings')->insert([
            'id'                 => 1,
            'telmex_base_amount' => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function down(): void
    {
        // DML BEFORE DDL — non-negotiable (design D7 / risk table). A
        // TELMEX_IU profile MUST be remapped to IU before the ENUM MODIFY
        // shrinks back to 2 values, or MySQL silently truncates the
        // out-of-range value to '' instead of raising an error.
        DB::table('scholarship_profiles')
            ->where('scholarship_type', 'TELMEX_IU')
            ->update(['scholarship_type' => 'IU']);

        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->dropColumn('iu_payment_amount');
        });

        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn('snapshot_telmex_covered_amount');
        });

        Schema::dropIfExists('scholarship_settings');

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE scholarship_profiles
                MODIFY COLUMN scholarship_type
                ENUM('IU','TELMEX') NOT NULL DEFAULT 'IU'
            ");
        }
    }
}
