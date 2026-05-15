<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipAdjustmentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_refrend_id')
                ->constrained('scholarship_refrends')
                ->onDelete('cascade');

            $table->enum('adjustment_type', ['CREDIT', 'DEBIT']);
            $table->decimal('amount', 8, 2);
            $table->string('reason');

            $table->foreignId('applied_by_id')->constrained('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_adjustments');
    }
}
