<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->decimal('amount_pending_from_previous', 10, 2)
                ->default(0)
                ->after('final_amount')
                ->comment('Monto acumulado de refrendos retenidos anteriores pendientes de pago.');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn('amount_pending_from_previous');
        });
    }
};
