<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateScholarshipRefrendsTable extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_refrends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month'); // 1-12

            $table->enum('refrend_type', ['NORMAL', 'RETENCION', 'REEMBOLSO'])->default('NORMAL');

            $table->enum('status', [
                'DRAFT',
                'ATENCION_REVIEW',
                'PEDAGOGIA_REVIEW',
                'AUTHORIZED',
                'PAID',
                'WITHHELD',
                'CANCELLED',
            ])->default('DRAFT');

            // Montos (congelados al generar)
            $table->decimal('base_amount', 8, 2);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('discount_amount', 8, 2)->default(0);
            $table->decimal('final_amount', 8, 2);

            // Snapshot histórico (congelado al momento de generación)
            $table->string('snapshot_name');
            $table->string('snapshot_generation')->nullable();
            $table->string('snapshot_campus')->nullable();
            $table->string('snapshot_scholarship_type')->default('IU');

            // Revisión Atención de Becarios
            $table->text('atencion_observations')->nullable();
            $table->foreignId('atencion_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('atencion_reviewed_at')->nullable();

            // Revisión Pedagogía
            $table->text('pedagogia_observations')->nullable();
            $table->foreignId('pedagogia_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pedagogia_reviewed_at')->nullable();

            // Lock: cuando está PAID o AUTHORIZED, no se puede modificar
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Un becario solo puede tener un refrendo de cada tipo por periodo
            // Nombre corto para respetar el límite de 64 chars de MySQL
            $table->unique(
                ['user_id', 'period_year', 'period_month', 'refrend_type'],
                'sc_refrends_period_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarship_refrends');
    }
}
