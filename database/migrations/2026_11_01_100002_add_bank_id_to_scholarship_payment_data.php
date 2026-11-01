<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/catalogo-bancos PR2: link payment data to the bank catalog. bank_name is
 * kept for one release (display/legacy only, no business logic).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('scholarship_payment_data', 'bank_id')) {
            return;
        }

        Schema::table('scholarship_payment_data', function (Blueprint $table) {
            $table->foreignId('bank_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('banks')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('scholarship_payment_data', 'bank_id')) {
            return;
        }

        Schema::table('scholarship_payment_data', function (Blueprint $table) {
            $table->dropForeign(['bank_id']);
            $table->dropColumn('bank_id');
        });
    }
};
