<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/telmex-cobertura-iu, design D8/D9: mirrors
 * scholarship_withholding_payments (2026_07_31_000003) — repayments the
 * becario makes against the ledger once Telmex starts paying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_telmex_coverage_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coverage_id');
            $table->decimal('amount', 10, 2);
            $table->date('paid_at');
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->boolean('is_voided')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_id')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('coverage_id')->references('id')->on('scholarship_telmex_coverages')->onDelete('cascade');
            $table->foreign('created_by_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('voided_by_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['coverage_id', 'is_voided']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_telmex_coverage_payments');
    }
};
