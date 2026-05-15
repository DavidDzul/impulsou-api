<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');

            $table->enum('scholarship_type', ['IU', 'TELMEX'])->default('IU');

            $table->decimal('monthly_amount', 8, 2);
            $table->date('payment_start_date');
            $table->date('payment_end_date')->nullable();

            // Descuento académico vigente (basado en promedio)
            $table->decimal('active_discount_percentage', 5, 2)->nullable();
            $table->date('discount_valid_until')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_profiles');
    }
}
