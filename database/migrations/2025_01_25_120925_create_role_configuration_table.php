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
        Schema::create('role_configuration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->unique()->constrained('roles');
            $table->integer('num_visualizations')->default(0);
            $table->integer('num_vacancies')->default(0);
            $table->boolean('unlimited')->default(false);
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
