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
            $table->enum("mode", ["IN_PERSON", "REMOTE", "HYBRID"]);
            $table->enum("category", ["JOB_POSITION", "PROFESSIONAL_PRACTICE", "JR_POSITION"]);
            $table->text('activities');
            $table->string('study_profile');
            $table->boolean('financial_support')->nullable()->default(false);
            $table->string('net_salary')->nullable();
            $table->string('support_amount')->nullable();
            $table->string('start_day');
            $table->string('end_day');
            $table->string('start_hour');
            $table->string('start_minute');
            $table->string('end_hour');
            $table->string('end_minute');
            $table->boolean('saturday_hour')->nullable()->default(false);
            $table->string('saturday_start_hour')->nullable();
            $table->string('saturday_start_minute')->nullable();
            $table->string('saturday_end_hour')->nullable();
            $table->string('saturday_end_minute')->nullable();
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

            $table->boolean('utilities')->nullable()->default(false);
            $table->boolean('bonuses')->nullable()->default(false);
            $table->boolean('dining_room')->nullable()->default(false);
            $table->boolean('savings_fund')->nullable()->default(false);
            $table->boolean('grocery_vouchers')->nullable()->default(false);
            $table->boolean('extensive_vacation_bonus')->nullable()->default(false);
            $table->boolean('top_christmas_bonus')->nullable()->default(false);
            $table->boolean('flexible_hours')->nullable()->default(false);
            $table->boolean('major_medical_expenses')->nullable()->default(false);
            $table->boolean('transportation_help')->nullable()->default(false);
            $table->boolean('automobile')->nullable()->default(false);
            $table->boolean('loans')->nullable()->default(false);
            $table->boolean('life_insurance')->nullable()->default(false);
            $table->boolean('other')->nullable()->default(false);
            $table->string('benefit_description')->nullable();
            $table->text('compensations')->nullable();

            $table->boolean('status')->default(true);
            $table->enum("candidate_type", ["INTERNAL", "EXTERNAL", "NOT_COVERED"])->nullable(true);
            $table->enum("campus", ["MERIDA", "VALLADOLID", "OXKUTZCAB", "TIZIMIN"]);
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