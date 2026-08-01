<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipWithholdingPaymentsTable extends Migration
{
    public function up()
    {
        Schema::create('scholarship_withholding_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('withholding_id');
            $table->unsignedBigInteger('applied_refrend_id');
            $table->decimal('amount', 10, 2);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->boolean('is_voided')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by_id')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('withholding_id')->references('id')->on('scholarship_withholdings')->onDelete('cascade');
            $table->foreign('applied_refrend_id')->references('id')->on('scholarship_refrends')->onDelete('cascade');
            $table->foreign('created_by_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('voided_by_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['withholding_id', 'is_voided']);
            $table->index('applied_refrend_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('scholarship_withholding_payments');
    }
}
