<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Header table for one advance-payment batch (design "Schema /
 * scholarship_advance_payments"). One row per batch of 1-3 future months
 * advanced against a single origin refrend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_advance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // One batch per originating refrend — mirrors
            // scholarship_withholdings.unique(origin_refrend_id). Makes
            // MAX_ADVANCED_MONTHS unambiguous (no cumulative-cap ambiguity
            // across batches).
            $table->foreignId('origin_refrend_id')
                ->unique()
                ->constrained('scholarship_refrends')
                ->onDelete('cascade');

            // Denormalized so the "batch from {mes/año}" label needs no join.
            $table->unsignedSmallInteger('origin_period_year');
            $table->unsignedTinyInteger('origin_period_month');

            $table->unsignedTinyInteger('months_count');
            $table->decimal('total_amount', 10, 2);

            // ACTIVE only in this feature; VOIDED reserved, never written —
            // void/reversal is explicitly out of scope (design D6/open items).
            $table->string('status', 20)->default('ACTIVE');

            $table->string('cause', 200)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_advance_payments');
    }
};
