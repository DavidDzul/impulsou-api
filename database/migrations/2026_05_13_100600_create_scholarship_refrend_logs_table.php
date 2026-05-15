<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipRefrendLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_refrend_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_refrend_id')
                ->constrained('scholarship_refrends')
                ->onDelete('cascade');

            $table->string('action'); // authorized, paid, atencion_review, pedagogia_review, withheld, etc.
            $table->text('notes')->nullable();

            $table->foreignId('performed_by_id')->constrained('users')->onDelete('cascade');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_refrend_logs');
    }
}
