<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpdateStudentDocumentsAddDescriptionRemoveCalificaciones extends Migration
{
    public function up(): void
    {
        // Cambiar enum eliminando CALIFICACIONES_ORIGINALES
        // (existentes con ese tipo se deberían haber migrado antes o quedarán inválidos)
        DB::statement("
            ALTER TABLE student_documents
            MODIFY COLUMN document_type
            ENUM('CONSTANCIA_ESTUDIOS','COMPROBANTE_PAGO','JUSTIFICANTE_MEDICO','OTRO') NOT NULL
        ");

        Schema::table('student_documents', function (Blueprint $table) {
            $table->string('description')->nullable()->after('rejected_reason');
            $table->text('observations')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('student_documents', function (Blueprint $table) {
            $table->dropColumn(['description', 'observations']);
        });

        DB::statement("
            ALTER TABLE student_documents
            MODIFY COLUMN document_type
            ENUM('CALIFICACIONES_ORIGINALES','CONSTANCIA_ESTUDIOS','COMPROBANTE_PAGO','JUSTIFICANTE_MEDICO','OTRO') NOT NULL
        ");
    }
}
