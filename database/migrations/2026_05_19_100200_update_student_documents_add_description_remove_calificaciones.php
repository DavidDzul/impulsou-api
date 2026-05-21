<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpdateStudentDocumentsAddDescriptionRemoveCalificaciones extends Migration
{
    public function up(): void
    {
        // MODIFY COLUMN is MySQL-only; skip on SQLite (test environment)
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE student_documents
                MODIFY COLUMN document_type
                ENUM('CONSTANCIA_ESTUDIOS','COMPROBANTE_PAGO','JUSTIFICANTE_MEDICO','OTRO') NOT NULL
            ");
        }

        Schema::table('student_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('student_documents', 'description')) {
                $table->string('description')->nullable()->after('rejected_reason');
            }
            if (!Schema::hasColumn('student_documents', 'observations')) {
                $table->text('observations')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_documents', function (Blueprint $table) {
            $table->dropColumn(['description', 'observations']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                ALTER TABLE student_documents
                MODIFY COLUMN document_type
                ENUM('CALIFICACIONES_ORIGINALES','CONSTANCIA_ESTUDIOS','COMPROBANTE_PAGO','JUSTIFICANTE_MEDICO','OTRO') NOT NULL
            ");
        }
    }
}
