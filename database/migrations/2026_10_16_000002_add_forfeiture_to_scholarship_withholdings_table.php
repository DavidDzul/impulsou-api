<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/egresado-baja-definitiva, design D11 — `status` is already a
 * string(20) column, so no enum DDL is needed for the new FORFEITED value.
 * Only the forfeiture bookkeeping columns are added here. Nullable and
 * additive, reversible via down().
 */
class AddForfeitureToScholarshipWithholdingsTable extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_withholdings', function (Blueprint $table) {
            $table->string('forfeited_reason', 30)->nullable()->after('status');
            $table->timestamp('forfeited_at')->nullable()->after('forfeited_reason');
            $table->unsignedBigInteger('forfeited_by_id')->nullable()->after('forfeited_at');

            $table->foreign('forfeited_by_id')
                ->references('id')->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_withholdings', function (Blueprint $table) {
            $table->dropForeign(['forfeited_by_id']);
            $table->dropColumn(['forfeited_reason', 'forfeited_at', 'forfeited_by_id']);
        });
    }
}
