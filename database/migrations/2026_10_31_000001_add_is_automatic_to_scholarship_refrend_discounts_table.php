<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/refrendo-mes-siguiente verify-fix: explicit marker for machine-generated
 * attendance discounts (RETARDOS / automatic FALTA_INJUSTIFICADA).
 *
 * Recalculation used to infer "automatic" from owning consumption rows, which
 * fails for legacy rows (no consumptions) and for rows whose attendance was
 * deleted (cascade removed the consumptions): they were treated as manual and
 * duplicated. The flag defaults to false so manual retentions stay manual.
 *
 * Backfill marks legacy automatic rows: RETARDOS (always automatic), FALTA
 * rows backed by consumptions, and FALTA rows carrying the automatic
 * description of either generation of the penalty service.
 *
 * Purely additive with a default, so the previous API keeps working if this
 * runs first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrend_discounts', function (Blueprint $table) {
            $table->boolean('is_automatic')->default(false)->after('description');
        });

        DB::table('scholarship_refrend_discounts')
            ->where('discount_type', 'RETARDOS')
            ->update(['is_automatic' => true]);

        DB::table('scholarship_refrend_discounts')
            ->where('discount_type', 'FALTA_INJUSTIFICADA')
            ->where(function ($q) {
                $q->whereIn('id', DB::table('scholarship_late_consumptions')->select('scholarship_refrend_discount_id'))
                    ->orWhere('description', 'like', 'Suspensión del mes por falta injustificada%');
            })
            ->update(['is_automatic' => true]);
    }

    public function down(): void
    {
        Schema::table('scholarship_refrend_discounts', function (Blueprint $table) {
            $table->dropColumn('is_automatic');
        });
    }
};
