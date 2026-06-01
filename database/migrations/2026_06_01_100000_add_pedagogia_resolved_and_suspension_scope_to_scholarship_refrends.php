<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->foreignId('pedagogia_resolved_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('pedagogia_reviewed_at');

            $table->timestamp('pedagogia_resolved_at')
                ->nullable()
                ->after('pedagogia_resolved_by_id');

            $table->string('suspension_scope', 20)
                ->nullable()
                ->after('suspension_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropForeign(['pedagogia_resolved_by_id']);
            $table->dropColumn(['pedagogia_resolved_by_id', 'pedagogia_resolved_at', 'suspension_scope']);
        });
    }
};
