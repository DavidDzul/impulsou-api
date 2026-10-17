<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/perfil-beca-montos-admin — config_set_at marks that Administración
 * saved the beca amounts (written ONLY by ScholarshipProfileController::
 * updateConfig). NULL means "amounts pending": refrend generation skips the
 * profile. Nullable and additive; no backfill (existing profiles stay NULL
 * until Administración saves their config).
 */
class AddConfigMarkerToScholarshipProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->timestamp('config_set_at')->nullable()->after('cycle_closed_refrend_id');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->dropColumn('config_set_at');
        });
    }
}
