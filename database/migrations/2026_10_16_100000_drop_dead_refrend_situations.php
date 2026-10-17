<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/limpieza-situaciones-en-desuso (api-3, amendment #2019): guard-only.
 *
 * SUSPENDIDA, REEMBOLSO_PARCIAL and PENDIENTE_NOTIFICACION no longer exist in
 * code. This migration NEVER deletes or rewrites data: if any row still
 * carries a dead value it aborts naming the refrend ids so they are cleaned
 * manually and the migration re-run. On a clean database it drops the three
 * dead columns, one hasColumn-guarded dropColumn per Schema::table call
 * (SQLite + dbal cannot drop several columns in one call).
 */
return new class extends Migration
{
    private const DEAD_RESOLUTIONS = ['SUSPENDIDA', 'REEMBOLSO_PARCIAL'];
    private const DEAD_WORKFLOW_STATUS = 'PENDIENTE_NOTIFICACION';

    public function up()
    {
        $this->guardAgainstDeadRows();

        foreach (['suspension_percentage', 'suspension_scope', 'refund_amount_from_previous'] as $column) {
            $this->dropRefrendColumn($column);
        }
    }

    public function down()
    {
        if (!Schema::hasColumn('scholarship_refrends', 'suspension_percentage')) {
            Schema::table('scholarship_refrends', function (Blueprint $table) {
                $table->decimal('suspension_percentage', 5, 2)->nullable();
            });
        }
        if (!Schema::hasColumn('scholarship_refrends', 'suspension_scope')) {
            Schema::table('scholarship_refrends', function (Blueprint $table) {
                $table->string('suspension_scope', 20)->nullable();
            });
        }
        if (!Schema::hasColumn('scholarship_refrends', 'refund_amount_from_previous')) {
            Schema::table('scholarship_refrends', function (Blueprint $table) {
                $table->decimal('refund_amount_from_previous', 10, 2)->default(0);
            });
        }
    }

    private function dropRefrendColumn(string $column): void
    {
        if (Schema::hasColumn('scholarship_refrends', $column)) {
            Schema::table('scholarship_refrends', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }

    private function guardAgainstDeadRows(): void
    {
        $ids = $this->offendingRefrendIds();

        if ($ids !== []) {
            throw new \RuntimeException(
                'Cannot drop dead refrend situation columns: scholarship_refrends ids ['
                . implode(', ', $ids)
                . '] still carry SUSPENDIDA / REEMBOLSO_PARCIAL / PENDIENTE_NOTIFICACION or a non-zero '
                . 'refund_amount_from_previous. Clean them manually and re-run the migration.'
            );
        }
    }

    /** @return array<int, int> */
    private function offendingRefrendIds(): array
    {
        $refrends = DB::table('scholarship_refrends')
            ->where(function ($query) {
                $query->whereIn('resolution_type', self::DEAD_RESOLUTIONS)
                    ->orWhere('workflow_status', self::DEAD_WORKFLOW_STATUS);
                if (Schema::hasColumn('scholarship_refrends', 'refund_amount_from_previous')) {
                    $query->orWhere('refund_amount_from_previous', '<>', 0);
                }
            })
            ->pluck('id')
            ->all();

        $viaAdvanceMonths = DB::table('scholarship_advance_payment_months')
            ->whereIn('settled_resolution_type', self::DEAD_RESOLUTIONS)
            ->whereNotNull('refrend_id')
            ->pluck('refrend_id')
            ->all();

        $ids = array_values(array_unique(array_map('intval', array_merge($refrends, $viaAdvanceMonths))));
        sort($ids);

        return $ids;
    }
};
