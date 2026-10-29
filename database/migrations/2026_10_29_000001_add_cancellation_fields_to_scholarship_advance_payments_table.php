<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/revertir-pago-adelantado (design A1): cancellation metadata on the
 * advance header. A manual cycle cut (BAJA DEFINITIVA / EGRESO) cancels the
 * advances instead of blocking; the header keeps the history of who/when/why
 * and, when the origin was already paid, the overpayment that administration
 * must recover.
 *
 * Purely additive and nullable/defaulted, so the previous API keeps working
 * if this runs first. Header status values (string(20), already wide enough
 * for PARTIALLY_CANCELLED): ACTIVE | PARTIALLY_CANCELLED | CANCELLED.
 *
 * down() guards dropForeign for sqlite the same way other migrations do: the
 * SQLite test driver rebuilds the table on dropColumn and does not support an
 * explicit dropForeign. On MySQL the constraints must go before the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_advance_payments', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('created_by_id');

            $table->foreignId('cancelled_by_id')
                ->nullable()
                ->after('cancelled_at')
                ->constrained('users')
                ->nullOnDelete();

            // CycleClosureReason value (BAJA_DEFINITIVA | EGRESADO).
            $table->string('cancel_reason', 20)->nullable()->after('cancelled_by_id');

            $table->foreignId('cancelled_cut_refrend_id')
                ->nullable()
                ->after('cancel_reason')
                ->constrained('scholarship_refrends')
                ->nullOnDelete();

            // Sum of the months this cut cancelled.
            $table->decimal('cancelled_amount', 10, 2)->default(0)->after('cancelled_cut_refrend_id');

            // Part of cancelled_amount that was already paid to the becario.
            $table->decimal('overpaid_amount', 10, 2)->default(0)->after('cancelled_amount');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_advance_payments', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['cancelled_by_id']);
                $table->dropForeign(['cancelled_cut_refrend_id']);
            }

            $table->dropColumn([
                'cancelled_at',
                'cancelled_by_id',
                'cancel_reason',
                'cancelled_cut_refrend_id',
                'cancelled_amount',
                'overpaid_amount',
            ]);
        });
    }
};
