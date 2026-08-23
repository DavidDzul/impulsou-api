<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->date('discount_valid_from')
                ->nullable()
                ->after('discount_reason');
        });

        // Backfill mandatory: without this, any row that already had an active
        // discount (active_discount_percentage set) would see it deactivated
        // the moment discount_valid_from is evaluated as part of the inclusive
        // [from, until] range, since a null valid_from now means "inactive"
        // instead of "no start restriction". DATE(created_at) is used (not
        // updated_at) because it is guaranteed to be <= today and predates any
        // discount ever captured on the row. Portable across MySQL and SQLite.
        DB::table('scholarship_profiles')
            ->whereNotNull('active_discount_percentage')
            ->whereNull('discount_valid_from')
            ->update(['discount_valid_from' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->dropColumn('discount_valid_from');
        });
    }
};
