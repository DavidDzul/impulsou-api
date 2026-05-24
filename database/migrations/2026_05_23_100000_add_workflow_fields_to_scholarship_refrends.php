<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->string('workflow_status', 50)->default('DRAFT')->after('status')->index();
            $table->string('resolution_type', 20)->nullable()->after('workflow_status');
            $table->string('resolution_cause', 100)->nullable()->after('resolution_type');
            $table->text('resolution_notes')->nullable()->after('resolution_cause');
            $table->decimal('suspension_percentage', 5, 2)->nullable()->after('resolution_notes');
            $table->unsignedTinyInteger('carryover_months_count')->nullable()->after('suspension_percentage');
            $table->string('carryover_months_detail', 255)->nullable()->after('carryover_months_count');
            $table->foreignId('notified_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('carryover_months_detail');
            $table->timestamp('notified_at')->nullable()->after('notified_by_id');
            $table->string('notification_method', 50)->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->dropForeign(['notified_by_id']);
            $table->dropIndex(['workflow_status']);
            $table->dropColumn([
                'workflow_status',
                'resolution_type',
                'resolution_cause',
                'resolution_notes',
                'suspension_percentage',
                'carryover_months_count',
                'carryover_months_detail',
                'notified_by_id',
                'notified_at',
                'notification_method',
            ]);
        });
    }
};
