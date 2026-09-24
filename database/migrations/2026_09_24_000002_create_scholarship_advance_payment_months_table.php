<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Child table: one row per advanced future period (design "Schema /
 * scholarship_advance_payment_months"). The
 * unique(user_id, period_year, period_month) constraint is the double-claim
 * guard — spec "Requirement: Child table uniqueness constraint" — so two
 * advance-payment records can never claim the same future month, even under
 * concurrent requests. Short custom index names to respect MySQL's 64-char
 * limit, matching sc_refrends_period_unique's existing precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_advance_payment_months', function (Blueprint $table) {
            $table->id();

            $table->foreignId('advance_payment_id')
                ->constrained('scholarship_advance_payments')
                ->onDelete('cascade');

            // Denormalized so the double-claim constraint can live on this
            // table without a join back to the header.
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            // Snapshot of the future refrend's computed final_amount —
            // server-computed, never client-supplied (mirrors
            // ScholarshipWithholdingPayment::amount's canonical-value
            // precedent).
            $table->decimal('amount', 10, 2);

            // The pre-created future refrend this child settles.
            $table->foreignId('refrend_id')
                ->unique()
                ->constrained('scholarship_refrends')
                ->onDelete('cascade');

            $table->string('status', 20)->default('PENDING');
            $table->timestamp('reached_at')->nullable();

            // Divergence tracking (design D4) — kept on this ledger row, NOT
            // on scholarship_refrends.resolution_cause, so the advance ledger
            // stays self-contained and independently queryable.
            $table->string('settled_resolution_type', 30)->nullable();
            $table->string('divergence_reason', 200)->nullable();

            $table->timestamps();

            $table->unique(
                ['user_id', 'period_year', 'period_month'],
                'sc_adv_pay_months_period_unique'
            );
            $table->index(['user_id', 'status'], 'sc_adv_pay_months_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_advance_payment_months');
    }
};
