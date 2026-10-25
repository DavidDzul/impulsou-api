<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/pago-cheque, design D3: how a refrend was paid (snapshot) plus the
 * cheque metadata. Named `paid_payment_method` so rows() never has two
 * `payment_method` columns once joined with scholarship_payment_data.
 */
class AddChequeColumnsToScholarshipRefrends extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->string('paid_payment_method', 20)->nullable();
            $table->string('cheque_number', 50)->nullable();
            $table->date('cheque_delivered_at')->nullable();
            $table->string('cheque_notes', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn([
                'paid_payment_method',
                'cheque_number',
                'cheque_delivered_at',
                'cheque_notes',
            ]);
        });
    }
}
