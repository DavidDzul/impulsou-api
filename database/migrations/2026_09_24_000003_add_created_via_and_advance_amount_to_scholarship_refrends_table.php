<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design D2 (`created_via`) + D6 (`advance_payment_amount`).
 *
 * D2: provenance marker distinguishing rows created by normal generation
 * from rows created by the advance-payment path, readable from the row
 * itself without a join. `exists()`-based idempotency in
 * GenerateMonthlyRefrendsService keeps matching on refrend_type=NORMAL
 * unchanged.
 *
 * D6: the advanced amount must reach total_to_pay. Written only by
 * RecordAdvancePaymentAction (PR3) on the origin refrend. Kept separate from
 * amount_pending_from_previous, which means retained-debt liquidation and
 * drives has_pending_from_previous/only_pending_from_previous — conflating
 * them would corrupt an existing chip and the syncer's invariant.
 *
 * Purely additive, no backfill needed: existing rows default to
 * created_via='GENERATION', advance_payment_amount=0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->string('created_via', 20)->default('GENERATION')->after('refrend_type');
            $table->decimal('advance_payment_amount', 10, 2)->default(0)->after('refund_amount_from_previous');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn(['created_via', 'advance_payment_amount']);
        });
    }
};
