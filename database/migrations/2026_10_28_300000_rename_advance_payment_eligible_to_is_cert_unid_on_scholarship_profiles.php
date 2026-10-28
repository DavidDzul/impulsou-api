<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * sdd/cert-unid-historial PR3: the flag that marks a becario as paid in the
 * CERT_UNID ("pago adelantado") batch is renamed from advance_payment_eligible
 * to is_cert_unid, so it stops being confused with the advance-months
 * columns (advance_payment_amount, advance_paid*).
 *
 * Raw SQL with a driver guard instead of Schema::renameColumn: Laravel 8 +
 * doctrine/dbal introspects the whole table and fails on the ENUM
 * scholarship_type ("Unknown database type enum"). CHANGE (rather than
 * RENAME COLUMN) works on every MySQL/MariaDB version and restates the
 * original definition exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('advance_payment_eligible', 'is_cert_unid');
    }

    public function down(): void
    {
        $this->rename('is_cert_unid', 'advance_payment_eligible');
    }

    private function rename(string $from, string $to): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE scholarship_profiles CHANGE {$from} {$to} TINYINT(1) NOT NULL DEFAULT 0");

            return;
        }

        DB::statement("ALTER TABLE scholarship_profiles RENAME COLUMN {$from} TO {$to}");
    }
};
