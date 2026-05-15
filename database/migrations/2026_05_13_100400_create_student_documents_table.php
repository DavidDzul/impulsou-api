<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStudentDocumentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Periodo al que pertenece el documento
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month'); // 1-12

            $table->enum('document_type', [
                'CALIFICACIONES_ORIGINALES',
                'CONSTANCIA_ESTUDIOS',
                'COMPROBANTE_PAGO',
                'JUSTIFICANTE_MEDICO',
                'OTRO',
            ]);

            $table->enum('status', ['PENDING', 'SUBMITTED', 'ACCEPTED', 'REJECTED'])
                ->default('PENDING');

            // Archivo en Storage (storage/app/public/scholarships/...)
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable(); // bytes
            $table->unsignedTinyInteger('version')->default(1);

            $table->text('rejected_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
}
