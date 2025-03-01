<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTechnicalKnowledge extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('technical_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum("type", ["SOFTWARE", "LANGUAGE", "OTHER"]);
            $table->string('other_knowledge')->nullable();
            $table->text('description_knowledge');
            $table->enum("level", ["BEGINNER", "INTERMEDIATE", "ADVANCED"]);
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
        Schema::dropIfExists('technical_knowledge');
    }
}