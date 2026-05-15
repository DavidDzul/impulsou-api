<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipLateConsumptionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_late_consumptions', function (Blueprint $table) {
            $table->id();

            // Nombre corto para respetar límite de 64 chars de MySQL
            $table->unsignedBigInteger('scholarship_refrend_discount_id');
            $table->foreign('scholarship_refrend_discount_id', 'sc_late_consum_discount_fk')
                ->references('id')
                ->on('scholarship_refrend_discounts')
                ->onDelete('cascade');

            $table->foreignId('attendance_id')
                ->constrained('attendances')
                ->onDelete('cascade');

            $table->timestamp('created_at')->useCurrent();

            // Un retardo solo puede consumirse una vez
            $table->unique('attendance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_late_consumptions');
    }
}
