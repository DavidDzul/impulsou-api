<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->enum('withholding_mode', ['percentage', 'fixed'])
                ->nullable()
                ->after('suspension_percentage');

            $table->decimal('withholding_value', 10, 2)
                ->nullable()
                ->after('withholding_mode');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn(['withholding_mode', 'withholding_value']);
        });
    }
};
