<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipWithholdingsTable extends Migration
{
    public function up()
    {
        Schema::create('scholarship_withholdings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('origin_refrend_id');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->decimal('withheld_amount', 10, 2);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->string('status', 20)->default('PENDING');
            $table->string('cause', 200)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('origin_refrend_id')->references('id')->on('scholarship_refrends')->onDelete('cascade');
            $table->foreign('created_by_id')->references('id')->on('users')->onDelete('set null');
            $table->unique('origin_refrend_id');
            $table->index(['user_id', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('scholarship_withholdings');
    }
}
