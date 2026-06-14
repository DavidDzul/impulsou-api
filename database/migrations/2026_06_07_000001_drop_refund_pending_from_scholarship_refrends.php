<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('scholarship_refrends', 'refund_pending_from_previous')) {
            Schema::table('scholarship_refrends', function (Blueprint $table) {
                $table->dropColumn('refund_pending_from_previous');
            });
        }
    }

    public function down(): void
    {
        Schema::table('scholarship_refrends', function (Blueprint $table) {
            $table->decimal('refund_pending_from_previous', 10, 2)->default(0)->after('refund_amount_from_previous');
        });
    }
};
