<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipRefrendDiscountsTable extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_refrend_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_refrend_id')
                ->constrained('scholarship_refrends')
                ->onDelete('cascade');

            $table->enum('discount_type', [
                'RETARDOS',
                'FALTA_INJUSTIFICADA',
                'PROMEDIO_BAJO',
                'DOCUMENTOS',
                'RECAUDACION',
                'OTRO',
            ]);

            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->string('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_refrend_discounts');
    }
}
