<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCurriculumTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('curriculum', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->integer('day_birth');
            $table->integer('month_birth');
            $table->integer('year_birth');
            $table->string('phone_num');
            $table->string('country');
            $table->string('state');
            $table->string('locality');
            $table->string('linkedin')->nullable();
            $table->text('professional_summary');
            $table->string('skill_1')->nullable();
            $table->string('skill_2')->nullable();
            $table->string('skill_3')->nullable();
            $table->string('skill_4')->nullable();
            $table->string('skill_5')->nullable();
            $table->boolean('public')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('curriculum');
    }
}
