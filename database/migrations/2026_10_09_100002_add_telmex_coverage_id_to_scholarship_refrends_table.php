<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/telmex-cobertura-iu, design D1/D2: the coverage id is FROZEN on the
 * refrend snapshot at generation/recalculation time — the payable Telmex
 * part (snapshot_telmex_covered_amount) only becomes payable when this FK
 * is set (ScholarshipRefrend::coveredPayableAmount()).
 *
 * FK restrict (not cascade/set-null): a deleted coverage must not silently
 * change payability on historical refrends. down() guards dropForeign for
 * sqlite the same way 2026_09_13_140000_add_payment_batch_id... does — the
 * SQLite test driver rebuilds the table on dropColumn and does not support
 * an explicit dropForeign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->unsignedBigInteger('snapshot_telmex_coverage_id')
                ->nullable()
                ->after('snapshot_telmex_covered_amount');

            $table->foreign('snapshot_telmex_coverage_id', 'sc_refrends_telmex_coverage_fk')
                ->references('id')->on('scholarship_telmex_coverages')
                ->onDelete('restrict');

            $table->index('snapshot_telmex_coverage_id', 'sc_refrends_telmex_coverage_idx');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign('sc_refrends_telmex_coverage_fk');
            }
            $table->dropIndex('sc_refrends_telmex_coverage_idx');
            $table->dropColumn('snapshot_telmex_coverage_id');
        });
    }
};
