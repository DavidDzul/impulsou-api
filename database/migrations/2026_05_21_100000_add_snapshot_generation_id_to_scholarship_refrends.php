<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->unsignedBigInteger('snapshot_generation_id')
                ->nullable()
                ->after('snapshot_generation');

            $table->foreign('snapshot_generation_id', 'sc_refrends_generation_fk')
                ->references('id')->on('generations')
                ->nullOnDelete();

            $table->index(
                ['period_year', 'period_month', 'snapshot_generation_id'],
                'sc_refrends_period_gen_idx'
            );
        });

        // Backfill best-effort por nombre — usando subquery compatible con SQLite y MySQL
        DB::statement("
            UPDATE scholarship_refrends
            SET snapshot_generation_id = (
                SELECT id FROM generations
                WHERE generations.generation_name = scholarship_refrends.snapshot_generation
                LIMIT 1
            )
            WHERE snapshot_generation IS NOT NULL
              AND snapshot_generation_id IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropIndex('sc_refrends_period_gen_idx');
            $table->dropForeign('sc_refrends_generation_fk');
            $table->dropColumn('snapshot_generation_id');
        });
    }
};
