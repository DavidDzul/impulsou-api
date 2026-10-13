<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/verificacion-incidencias-ux (PR4a, amendment #2): incidencias are never
 * "resolved" in practice — Verificación creates them (atencionFlag) and
 * deletes them from the same modal (atencionClearFlag), they are not marked
 * resolved. Drops the dead is_resolved/resolved_at/resolved_by_id/
 * resolution_notes columns from scholarship_refrend_incidents end-to-end.
 *
 * down() guards dropForeign for sqlite the same way
 * 2026_09_13_140000_add_payment_batch_id... and
 * 2026_10_09_100002_add_telmex_coverage_id... do — the SQLite test driver
 * rebuilds the table on dropColumn and does not support an explicit
 * dropForeign at all. On MySQL (production) the constraint must be dropped
 * before the column.
 */
class DropResolutionColumnsFromScholarshipRefrendIncidents extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('scholarship_refrend_incidents', 'resolved_by_id')) {
            Schema::table('scholarship_refrend_incidents', function (Blueprint $table) {
                if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                    $table->dropForeign(['resolved_by_id']);
                }
                $table->dropColumn(['is_resolved', 'resolved_at', 'resolved_by_id', 'resolution_notes']);
            });
        }
    }

    public function down()
    {
        if (!Schema::hasColumn('scholarship_refrend_incidents', 'is_resolved')) {
            Schema::table('scholarship_refrend_incidents', function (Blueprint $table) {
                $table->boolean('is_resolved')->default(false)->after('created_by_id');
                $table->timestamp('resolved_at')->nullable()->after('is_resolved');
                $table->unsignedBigInteger('resolved_by_id')->nullable()->after('resolved_at');
                $table->text('resolution_notes')->nullable()->after('resolved_by_id');

                $table->foreign('resolved_by_id')
                      ->references('id')->on('users')
                      ->onDelete('set null');
            });
        }
    }
}
