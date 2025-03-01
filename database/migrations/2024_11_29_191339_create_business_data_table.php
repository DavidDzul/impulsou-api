<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBusinessDataTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('business_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict')->unique();
            $table->string('bs_name');
            $table->string('bs_director');
            $table->string('bs_rfc');
            $table->string('bs_country');
            $table->string('bs_state');
            $table->string('bs_locality');
            $table->string('bs_adrress');
            $table->string('bs_telphone');
            $table->string('bs_line');
            $table->string('bs_other_line')->nullable();
            $table->text('bs_description');
            $table->string('bs_website');
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
        Schema::dropIfExists('business_data');
    }
}