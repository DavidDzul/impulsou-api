<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveVersionFromStudentDocumentsTable extends Migration
{
    public function up(): void
    {
        // SQLite does not support dropping columns without doctrine/dbal.
        // Skip on SQLite (test environment) since the extra column is harmless.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('student_documents', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }

    public function down(): void
    {
        Schema::table('student_documents', function (Blueprint $table) {
            $table->unsignedTinyInteger('version')->default(1)->after('file_size');
        });
    }
}
