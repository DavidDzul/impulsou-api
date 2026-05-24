<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipRefrendIncidentsTable extends Migration
{
    public function up()
    {
        Schema::create('scholarship_refrend_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scholarship_refrend_id');
            $table->string('incident_category', 50);
            $table->string('incident_type', 100);
            $table->date('incident_date')->nullable();
            $table->text('description');
            $table->string('priority', 20)->default('MEDIUM');
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by_id')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->foreign('scholarship_refrend_id')
                  ->references('id')->on('scholarship_refrends')
                  ->onDelete('cascade');

            $table->foreign('created_by_id')
                  ->references('id')->on('users')
                  ->onDelete('set null');

            $table->foreign('resolved_by_id')
                  ->references('id')->on('users')
                  ->onDelete('set null');

            $table->index('scholarship_refrend_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('scholarship_refrend_incidents');
    }
}
