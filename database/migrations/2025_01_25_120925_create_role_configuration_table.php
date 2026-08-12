<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRoleConfigurationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Schema::create('role_configuration', function (Blueprint $table) {
        //     $table->id();
        //     $table->foreignId('role_id')->unique()->constrained('roles');
        //     $table->integer('num_visualizations')->default(0);
        //     $table->integer('num_vacancies')->default(0);
        //     $table->boolean('unlimited')->default(false);
        //     $table->timestamps();
        // });

        Schema::create('role_configuration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->unique()->constrained('roles');
            $table->boolean('unlimited_jobs')->default(false);
            $table->integer('num_job_vacancies')->default(0);
            $table->boolean('unlimited_professionals')->default(false);
            $table->integer('num_professional_vacancies')->default(0);
            $table->boolean('unlimited_jr')->default(false);
            $table->integer('num_jr_vacancies')->default(0);
            $table->boolean('unlimited_visualizations')->default(false);
            $table->integer('num_visualizations')->default(0);
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
        Schema::dropIfExists('role_configuration');
    }
}
