<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DropSuspensionFieldsFromScholarshipProfilesTable extends Migration
{
    public function up(): void
    {
        // SQLite does not support dropping columns without doctrine/dbal.
        // Skip on SQLite (test environment); columns are phantom/harmless there.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        $cols = collect(['suspension_percentage', 'suspension_until', 'suspension_cause'])
            ->filter(fn (string $c) => Schema::hasColumn('scholarship_profiles', $c))
            ->values()
            ->all();

        if (empty($cols)) {
            // Columns never existed — nothing to drop.
            return;
        }

        // Safety guard: refuse to drop if any row has non-null data in these columns.
        $hasData = DB::table('scholarship_profiles')
            ->where(function ($q) use ($cols) {
                foreach ($cols as $c) {
                    $q->orWhereNotNull($c);
                }
            })
            ->exists();

        if ($hasData) {
            throw new \RuntimeException(
                'Aborting: scholarship_profiles has non-null suspension data. Review before dropping columns.'
            );
        }

        Schema::table('scholarship_profiles', function (Blueprint $table) use ($cols) {
            $table->dropColumn($cols);
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('scholarship_profiles', 'suspension_percentage')) {
                $table->decimal('suspension_percentage', 5, 2)->nullable();
            }
            if (!Schema::hasColumn('scholarship_profiles', 'suspension_until')) {
                $table->date('suspension_until')->nullable();
            }
            if (!Schema::hasColumn('scholarship_profiles', 'suspension_cause')) {
                $table->string('suspension_cause', 200)->nullable();
            }
        });
    }
}
