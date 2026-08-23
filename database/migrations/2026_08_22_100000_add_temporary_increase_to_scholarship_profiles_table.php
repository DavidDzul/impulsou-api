<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->decimal('temporary_increase_amount', 8, 2)
                ->nullable()
                ->after('monto_apoyo');

            $table->date('temporary_increase_valid_from')
                ->nullable()
                ->after('temporary_increase_amount');

            $table->date('temporary_increase_valid_until')
                ->nullable()
                ->after('temporary_increase_valid_from');

            $table->string('temporary_increase_reason', 200)
                ->nullable()
                ->after('temporary_increase_valid_until');

            $table->foreignId('temporary_increase_granted_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('temporary_increase_reason');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->dropForeign(['temporary_increase_granted_by_id']);
            $table->dropColumn([
                'temporary_increase_amount',
                'temporary_increase_valid_from',
                'temporary_increase_valid_until',
                'temporary_increase_reason',
                'temporary_increase_granted_by_id',
            ]);
        });
    }
};
