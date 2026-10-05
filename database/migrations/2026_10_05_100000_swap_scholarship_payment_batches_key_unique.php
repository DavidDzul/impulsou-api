<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/pagos-batch-sede-totals, design Part 1 — narrows
 * scholarship_payment_batches' unique key from
 * (generation_id, campus, period_year, period_month) to
 * (campus, period_year, period_month). A Pagos batch is now identified
 * solely by campus+period; a single batch's rows may span multiple
 * generaciones at that campus/period.
 *
 * ATOMICITY (MySQL DDL is non-transactional in L8): a bare drop-then-add
 * that failed partway would leave the table with NO uniqueness guard at
 * all — the exact silent-double-batch catastrophe this migration exists
 * to prevent. The pre-flight audit below runs BEFORE dropUnique(), so a
 * failed precondition aborts with the OLD index still fully intact.
 */
class SwapScholarshipPaymentBatchesKeyUnique extends Migration
{
    public function up(): void
    {
        // Pre-migration duplicate-batch audit (spec: "Pre-migration
        // duplicate-batch audit (deployment gate)"). Any campus+period
        // combination with 2+ existing rows differing only by
        // generation_id was legal under the OLD 4-column index but would
        // violate the NEW 3-column one — abort loudly instead of letting
        // dropUnique() + a failed re-add silently remove all protection.
        $duplicates = DB::table('scholarship_payment_batches')
            ->select('campus', 'period_year', 'period_month')
            ->groupBy('campus', 'period_year', 'period_month')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $offenders = $duplicates
                ->map(fn ($row) => sprintf('%s %04d-%02d', $row->campus, $row->period_year, $row->period_month))
                ->implode(', ');

            throw new RuntimeException(
                'Cannot swap the scholarship_payment_batches unique index: duplicate '
                . 'campus+period rows (differing only by generation_id) already exist '
                . "for: {$offenders}. Resolve these collisions before migrating — see "
                . 'spec "Pre-migration duplicate-batch audit (deployment gate)".'
            );
        }

        Schema::table('scholarship_payment_batches', function (Blueprint $table) {
            $table->dropUnique('sc_payment_batches_key_unique');
            $table->unique(
                ['campus', 'period_year', 'period_month'],
                'sc_payment_batches_key_unique'
            );
        });
    }

    public function down(): void
    {
        // Rollback-safety guard: the reverted code's duplicate-batch-guard
        // query filters by generation_id, which can never match a NULL
        // row. If any row already has generation_id IS NULL (written
        // under the new 3-column-key code this migration is undoing), the
        // restored 4-column unique index would permit a second batch for
        // that same campus+period — MySQL UNIQUE indexes treat NULL as
        // distinct from any other value, including another NULL — and the
        // batch would get paid twice. Refuse the rollback outright rather
        // than silently reopening that race.
        $hasNullGenerationRows = DB::table('scholarship_payment_batches')
            ->whereNull('generation_id')
            ->exists();

        if ($hasNullGenerationRows) {
            throw new RuntimeException(
                'Cannot roll back the scholarship_payment_batches unique index: rows '
                . 'with generation_id IS NULL exist. Restoring the 4-column unique index '
                . 'would not protect those rows against duplicate batches (MySQL does not '
                . 'treat NULLs as equal for uniqueness), reintroducing a double-payment '
                . 'race. Resolve or backfill generation_id before rolling back.'
            );
        }

        Schema::table('scholarship_payment_batches', function (Blueprint $table) {
            $table->dropUnique('sc_payment_batches_key_unique');
            $table->unique(
                ['generation_id', 'campus', 'period_year', 'period_month'],
                'sc_payment_batches_key_unique'
            );
        });
    }
}
