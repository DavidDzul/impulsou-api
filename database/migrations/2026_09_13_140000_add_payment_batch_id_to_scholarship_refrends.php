<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPaymentBatchIdToScholarshipRefrends extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->foreignId('payment_batch_id')
                ->nullable()
                ->after('locked_by_id')
                ->constrained('scholarship_payment_batches')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            // SQLite (used by the test suite) rebuilds the whole table on any
            // dropColumn and does not support an explicit dropForeign at all
            // (Blueprint::ensureCommandsAreValid throws unconditionally for
            // it) — the FK is dropped along with the column as part of that
            // rebuild, so the explicit dropForeign is both unsupported and
            // unnecessary there. On MySQL (production) the constraint must
            // be dropped before the column.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['payment_batch_id']);
            }
            $table->dropColumn('payment_batch_id');
        });
    }
}
