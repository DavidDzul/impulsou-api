<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateJobApplicationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('business_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('vacant_id')->constrained('vacant_position')->onDelete('restrict');
            $table->enum('status', ['PENDING', 'ACCEPTED', 'REJECTED'])->default('PENDING');
            $table->enum('rejected_by', ['BEC_ACTIVE', 'BUSINESS'])->nullable();
            $table->enum('rejected_reason', ['BS_UNSOLICITED', 'BS_WAS_COVERED', 'BS_NOT_REQUIRED', 'BS_USER_NOT_CONTINUE', 'US_FIND_JOB', 'US_NOT_EXPECTATIONS', 'US_PERSONAL_PROBLEMS', 'US_CONFUSION', 'OTHER'])->nullable();
            $table->string('rejected_other')->nullable();
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
        Schema::dropIfExists('job_applications');
    }
}