<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVacantPositionTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('vacant_position', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->string('vacant_name');
            $table->enum("category", ["JOB_POSITION", "PROFESSIONAL_PRACTICE"]);
            $table->text('activities');
            $table->string('study_profile');
            $table->boolean('financial_support')->nullable()->default(false);
            $table->string('net_salary')->nullable();
            $table->string('support_amount')->nullable();
            $table->string('start_day');
            $table->string('end_day');
            $table->string('start_hour');
            $table->string('end_hour');
            $table->boolean('saturday_hour')->nullable()->default(false);
            $table->string('saturday_start_day')->nullable();
            $table->string('saturday_end_day')->nullable();
            $table->text('additional_time_info')->nullable();
            $table->boolean('experience')->nullable()->default(false);
            $table->text('experience_description')->nullable();
            $table->boolean('software_use')->nullable()->default(false);
            $table->text('software_description')->nullable();
            $table->text('skills');
            $table->text('observations')->nullable();
            $table->string('semester')->nullable();
            $table->boolean('general_knowledge')->nullable()->default(false);
            $table->text('knowledge_description')->nullable();

            $table->boolean('employment_contract')->nullable()->default(false);
            $table->boolean('vacation')->nullable()->default(false);
            $table->boolean('christmas_bonus')->nullable()->default(false);
            $table->boolean('social_security')->nullable()->default(false);
            $table->boolean('vacation_bonus')->nullable()->default(false);
            $table->boolean('grocery_vouchers')->nullable()->default(false);
            $table->boolean('savings_fund')->nullable()->default(false);
            $table->boolean('life_insurance')->nullable()->default(false);
            $table->boolean('medical_expenses')->nullable()->default(false);
            $table->boolean('day_off')->nullable()->default(false);
            $table->boolean('sunday_bonus')->nullable()->default(false);
            $table->boolean('paternity_leave')->nullable()->default(false);
            $table->boolean('transportation_help')->nullable()->default(false);
            $table->boolean('productivity_bonus')->nullable()->default(false);
            $table->boolean('automobile')->nullable()->default(false);
            $table->boolean('dining_room')->nullable()->default(false);
            $table->boolean('loans')->nullable()->default(false);
            $table->boolean('other')->nullable()->default(false);
            $table->string('benefit_description')->nullable();

            $table->string('contact_name');
            $table->string('contact_position');
            $table->string('contact_telphone');
            $table->string('contact_email');
            $table->boolean('status')->default(false);
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
        Schema::dropIfExists('vacant_position');
    }
}
