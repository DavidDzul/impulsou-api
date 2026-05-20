<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsOriginalToScholarshipSemesterGradesTable extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_semester_grades', function (Blueprint $table) {
            $table->boolean('is_original')->default(false)->after('grade');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_semester_grades', function (Blueprint $table) {
            $table->dropColumn('is_original');
        });
    }
}
