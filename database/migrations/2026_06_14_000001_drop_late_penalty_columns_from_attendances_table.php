<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropLatePenaltyColumnsFromAttendancesTable extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // SQLite has no ALTER TABLE DROP CONSTRAINT; dropColumn() below
            // already recreates the table via doctrine/dbal, which drops the
            // constraint along with the column, so this step is MySQL/Postgres-only.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['late_penalty_consumed_refrend_id']);
            }
            $table->dropColumn(['late_penalty_consumed', 'late_penalty_consumed_refrend_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('late_penalty_consumed')->default(false)->after('status');
            $table->unsignedBigInteger('late_penalty_consumed_refrend_id')->nullable()->after('late_penalty_consumed');
            $table->foreign('late_penalty_consumed_refrend_id')
                ->references('id')
                ->on('scholarship_refrends')
                ->onDelete('set null');
        });
    }
}
