<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->decimal('carryover_percentage', 5, 2)->nullable()->after('carryover_months_detail');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn('carryover_percentage');
        });
    }
};
