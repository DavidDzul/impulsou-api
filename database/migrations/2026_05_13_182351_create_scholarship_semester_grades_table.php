<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipSemesterGradesTable extends Migration
{
    public function up()
    {
        Schema::create('scholarship_semester_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('semester_year');
            $table->unsignedTinyInteger('semester_period'); // 1 = Ene-Jul, 2 = Ago-Dic
            $table->decimal('grade', 5, 2)->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'semester_year', 'semester_period'], 'sc_grades_period_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('scholarship_semester_grades');
    }
}
