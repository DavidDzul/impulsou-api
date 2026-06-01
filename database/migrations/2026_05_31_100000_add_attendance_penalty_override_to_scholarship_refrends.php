<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->boolean('attendance_penalty_override')
                  ->default(false)
                  ->after('attendance_summary_snapshot')
                  ->comment('When true, automatic attendance penalties are not re-applied on recalculate.');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn('attendance_penalty_override');
        });
    }
};
