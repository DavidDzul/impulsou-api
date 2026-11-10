<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/bank-file-split-other-banks-cheques: one row per Beca / Apoyo concept of
 * a cheque or manual-transfer payment. Only the concepts with an amount > 0
 * get a row; the amount is frozen at mark time. This table is the only source
 * of truth for references, dates and notes of manual payments.
 */
class CreateScholarshipRefrendManualPaymentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_refrend_manual_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refrend_id')
                ->constrained('scholarship_refrends')
                ->onDelete('cascade');
            $table->string('concept', 10);          // beca | apoyo
            $table->string('method', 20);           // CHEQUE | MANUAL_TRANSFER
            $table->string('reference', 50);
            $table->string('reference_normalized', 50);
            // Same precision as scholarship_refrends.final_amount.
            $table->decimal('amount', 8, 2);
            $table->date('paid_at');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Short names keep MySQL under the 64 char identifier limit.
            $table->unique(['refrend_id', 'concept'], 'srmp_refrend_concept_unique');
            $table->unique(['method', 'reference_normalized'], 'srmp_method_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_refrend_manual_payments');
    }
}
