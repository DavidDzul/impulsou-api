<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * sdd/cert-unid-historial PR1: stamps the EXISTING cheque-paid refrends from
 * the becario's current flag (the best information available for history).
 * Batch-paid rows keep NULL (they follow payment_batch_id) and so do unpaid
 * ones. Idempotent: only rows still NULL are touched.
 *
 * Deliberately INLINE literal SQL and the column's name AT THIS POINT
 * (advance_payment_eligible): a later migration renames it to is_cert_unid,
 * so calling an app helper here would break a fresh `migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE scholarship_refrends
            SET paid_batch_type = CASE
                WHEN COALESCE((
                    SELECT sp.advance_payment_eligible
                    FROM scholarship_profiles sp
                    WHERE sp.user_id = scholarship_refrends.user_id
                ), 0) = 1 THEN 'CERT_UNID'
                ELSE 'NORMAL'
            END
            WHERE status = 'PAID'
              AND paid_payment_method = 'CHEQUE'
              AND payment_batch_id IS NULL
              AND paid_batch_type IS NULL
        SQL);
    }

    public function down(): void
    {
        // No-op: the DDL migration's down() drops the column, which discards
        // the stamps. Clearing them here would only add a pointless UPDATE.
    }
};
