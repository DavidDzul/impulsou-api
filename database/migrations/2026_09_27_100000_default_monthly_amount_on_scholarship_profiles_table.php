<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DefaultMonthlyAmountOnScholarshipProfilesTable extends Migration
{
    public function up(): void
    {
        // sdd/scholarship-profile-config-to-admin design D1: `monthly_amount`
        // stays NOT NULL but gains a DB-level default, so psicol-panel's
        // existing CREATE flow (which no longer sends this field, per
        // design D3) does not need any controller-level defaulting code.
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->decimal('monthly_amount', 8, 2)->default('0.00')->change();
        });

        // Guarded fix for a pre-existing, SQLite-only test-schema gap: the
        // column-drop migration (2026_05_19_100000_remove_payment_dates_...)
        // skips SQLite entirely, so `payment_start_date` remains NOT NULL
        // with no default there. That blocked any HTTP-level "store
        // succeeds with no config fields" test (see
        // ScholarshipProfileEndpointTest.php's historical NOTE). This is a
        // no-op on real databases, where the column was already dropped.
        if (Schema::hasColumn('scholarship_profiles', 'payment_start_date')) {
            Schema::table('scholarship_profiles', function (Blueprint $table) {
                $table->date('payment_start_date')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('scholarship_profiles', 'payment_start_date')) {
            Schema::table('scholarship_profiles', function (Blueprint $table) {
                $table->date('payment_start_date')->nullable(false)->change();
            });
        }

        Schema::table('scholarship_profiles', function (Blueprint $table) {
            $table->decimal('monthly_amount', 8, 2)->default(null)->change();
        });
    }
}
