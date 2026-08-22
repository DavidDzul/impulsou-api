<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->decimal('snapshot_temporary_increase_amount', 8, 2)
                ->nullable()
                ->after('snapshot_monto_apoyo');

            $table->string('snapshot_temporary_increase_reason', 200)
                ->nullable()
                ->after('snapshot_temporary_increase_amount');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn([
                'snapshot_temporary_increase_amount',
                'snapshot_temporary_increase_reason',
            ]);
        });
    }
};
