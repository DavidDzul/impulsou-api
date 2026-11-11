<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/bank-file-split-other-banks-cheques PR4: manual payment references now
 * live only in scholarship_refrend_manual_payments (one row per concept).
 * paid_payment_method stays on scholarship_refrends.
 */
class DropLegacyManualPaymentColumnsFromScholarshipRefrends extends Migration
{
    private const COLUMNS = [
        'cheque_number',
        'cheque_delivered_at',
        'cheque_notes',
        'transfer_reference',
        'transfer_paid_at',
        'transfer_notes',
    ];

    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->string('cheque_number', 50)->nullable();
            $table->date('cheque_delivered_at')->nullable();
            $table->string('cheque_notes', 500)->nullable();
            $table->string('transfer_reference', 50)->nullable();
            $table->date('transfer_paid_at')->nullable();
            $table->string('transfer_notes', 500)->nullable();
        });
    }
}
