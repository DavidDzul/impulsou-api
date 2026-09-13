<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipPaymentBatchesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('scholarship_payment_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('generation_id')->nullable();
            $table->string('campus', 50);
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedInteger('refrend_count');
            $table->decimal('total_amount', 10, 2);
            $table->foreignId('processed_by_id')->constrained('users');
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(
                ['generation_id', 'campus', 'period_year', 'period_month'],
                'sc_payment_batches_key_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('scholarship_payment_batches');
    }
}
