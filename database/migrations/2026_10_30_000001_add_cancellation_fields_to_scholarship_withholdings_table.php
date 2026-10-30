<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/retenciones-pendientes (design, data model): audit metadata on the
 * retention ledger. Every move to CANCELLED records when, why and (when a
 * person triggered it) who. Legacy CANCELLED rows keep NULL values: no backfill.
 *
 * Purely additive and nullable, so the previous API keeps working if this
 * runs first.
 *
 * down() guards dropForeign for sqlite the same way the advance-payment
 * cancellation migration does: the SQLite test driver rebuilds the table on
 * dropColumn and does not support an explicit dropForeign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_withholdings', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('settled_at');

            // WithholdingCancellationReason value.
            $table->string('cancellation_reason', 20)->nullable()->after('cancelled_at');

            $table->foreignId('cancelled_by_id')
                ->nullable()
                ->after('cancellation_reason')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_withholdings', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['cancelled_by_id']);
            }

            $table->dropColumn(['cancelled_at', 'cancellation_reason', 'cancelled_by_id']);
        });
    }
};
