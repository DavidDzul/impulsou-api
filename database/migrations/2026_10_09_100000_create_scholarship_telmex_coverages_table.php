<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sdd/telmex-cobertura-iu, design D7/D8: one coverage record per becario
 * (UNIQUE user_id — reactivation reuses the same CANCELADA row, see PR3a).
 * status is a plain string(20), not a MySQL ENUM, mirroring
 * scholarship_withholdings.status (no driver-specific ALTER needed later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_telmex_coverages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('scholarship_type_at_activation', 20);
            $table->date('start_period');
            $table->date('end_period')->nullable();
            $table->string('status', 20)->default('ACTIVA');
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('activated_by_id')->nullable();
            $table->unsignedBigInteger('ended_by_id')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('activated_by_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('ended_by_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('cancelled_by_id')->references('id')->on('users')->onDelete('set null');

            $table->unique('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_telmex_coverages');
    }
};
