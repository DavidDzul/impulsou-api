<?php

use App\Services\Scholarship\BackfillWithholdingLedgerService;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfills the retention ledger (`scholarship_withholdings`) from historical
 * WITHHELD refrends, then reproduces the net pending debt that
 * GenerateMonthlyRefrendsService::calculatePendingCarryover() used to compute
 * on the fly, as synthetic `scholarship_withholding_payments` child rows.
 *
 * The actual logic lives in BackfillWithholdingLedgerService so it can be
 * unit-tested against seeded data (Laravel migrations under RefreshDatabase
 * always run against an empty schema, so exercising the FIFO/backfill
 * behavior requires invoking the service directly in tests).
 *
 * Order matters: this migration MUST run after
 * 2026_07_31_000003_create_scholarship_withholding_payments_table (the child
 * table). If the ledger rows end up with paid_amount > 0 without matching
 * child rows, the first call to recomputePaidAmount() would zero them out and
 * resurrect already-settled debt (see design §8.6).
 */
class BackfillScholarshipWithholdings extends Migration
{
    public function up()
    {
        app(BackfillWithholdingLedgerService::class)->run();
    }

    public function down()
    {
        // Structural tables are owned by their own migrations (000002/000003)
        // and are rolled back independently, in reverse order, right after
        // this one — dropping them here would violate their FK constraints
        // while this migration's down() still runs first. Only the synthetic
        // data this migration itself inserted is reversed; see the service
        // for why step 3 of up() cannot be undone.
        app(BackfillWithholdingLedgerService::class)->revertSynthetic();
    }
}
