<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCandidateDataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('candidate_data', function (Blueprint $table) {
            $table->id();
            $table->enum('user_type', ['BEC_ACTIVE', 'BEC_INACTIVE']);
            $table->enum('campus', ['MERIDA', 'TIZIMIN', 'OXKUTZCAB', 'VALLADOLID']);
            $table->enum('job_type', ['PROFESSIONAL_PRACTICE', 'PART_TIME', 'FULL_TIME']);
            $table->foreignId('area_id')->constrained('areas')->onDelete('cascade');
            $table->integer('count');
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
        Schema::dropIfExists('candidate_data');
    }
}