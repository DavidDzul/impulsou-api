<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * sdd/limpieza-refrendos (A1): DML-only data fix, runs before the code that
 * drops the legacy RefrendStatus / DiscountType cases.
 *
 * 1. Legacy statuses (AUTHORIZED, ATENCION_REVIEW, PEDAGOGIA_REVIEW) are
 *    mapped deterministically: payment evidence -> PAID; unlocked + RETENIDA
 *    -> WITHHELD; any other unlocked row -> DRAFT.
 * 2. Automatic egreso rows (CLOSED + EGRESO_RETICULA) become CANCELLED, the
 *    status GenerateMonthlyRefrendsService now writes for new ones.
 *
 * Every guard runs completely before the first write and aborts naming the
 * offending ids, so ambiguous rows are resolved by hand. updated_at is not
 * touched (query builder updates only). Idempotent; down() is a no-op because
 * the legacy statuses carry no information to restore and old code already
 * treats CANCELLED + CLOSED as locked.
 */
return new class extends Migration
{
    private const LEGACY_STATUSES = ['AUTHORIZED', 'ATENCION_REVIEW', 'PEDAGOGIA_REVIEW'];
    private const WORKFLOW_STATUSES = ['DRAFT', 'CON_INCIDENCIA', 'LISTO_PARA_PAGO', 'CLOSED'];
    private const LEGACY_DISCOUNT_TYPES = ['DOCUMENTOS', 'RECAUDACION', 'OTRO'];
    private const EGRESO = 'EGRESO_RETICULA';

    public function up()
    {
        $this->guard();

        DB::transaction(function () {
            $this->legacy()->where(fn ($q) => $this->withPaymentEvidence($q))
                ->update(['status' => 'PAID']);

            $this->legacy()->where(fn ($q) => $this->withoutPaymentEvidence($q))
                ->where('workflow_status', '<>', 'CLOSED')
                ->whereNull('locked_at')
                ->where('resolution_type', 'RETENIDA')
                ->update(['status' => 'WITHHELD']);

            $this->legacy()->where(fn ($q) => $this->withoutPaymentEvidence($q))
                ->where('workflow_status', '<>', 'CLOSED')
                ->whereNull('locked_at')
                ->update(['status' => 'DRAFT']);

            DB::table('scholarship_refrends')
                ->where('resolution_type', self::EGRESO)
                ->where('workflow_status', 'CLOSED')
                ->where('status', 'DRAFT')
                ->update(['status' => 'CANCELLED']);
        });
    }

    /** Forward-only: see the class docblock. */
    public function down()
    {
    }

    private function legacy()
    {
        return DB::table('scholarship_refrends')->whereIn('status', self::LEGACY_STATUSES);
    }

    private function withPaymentEvidence($query): void
    {
        $query->whereNotNull('payment_batch_id')->orWhereNotNull('paid_payment_method');
    }

    private function withoutPaymentEvidence($query): void
    {
        $query->whereNull('payment_batch_id')->whereNull('paid_payment_method');
    }

    private function guard(): void
    {
        $problems = [];

        $closedOrLockedWithoutPayment = $this->legacy()
            ->where(fn ($q) => $this->withoutPaymentEvidence($q))
            ->where(fn ($q) => $q->where('workflow_status', 'CLOSED')->orWhereNotNull('locked_at'))
            ->pluck('id')->all();
        $problems['legacy status, closed/locked and without payment evidence'] = $closedOrLockedWithoutPayment;

        $problems['workflow_status outside ' . implode('/', self::WORKFLOW_STATUSES)] = DB::table('scholarship_refrends')
            ->whereNotIn('workflow_status', self::WORKFLOW_STATUSES)
            ->pluck('id')->all();

        $problems['removed discount_type (refrend ids)'] = DB::table('scholarship_refrend_discounts')
            ->whereIn('discount_type', self::LEGACY_DISCOUNT_TYPES)
            ->pluck('scholarship_refrend_id')->all();

        $problems['EGRESO_RETICULA with status other than DRAFT/CANCELLED or with a payment batch'] = DB::table('scholarship_refrends')
            ->where('resolution_type', self::EGRESO)
            ->where(fn ($q) => $q->whereNotIn('status', ['DRAFT', 'CANCELLED'])->orWhereNotNull('payment_batch_id'))
            ->pluck('id')->all();

        $messages = [];
        foreach ($problems as $check => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids);
            if ($ids !== []) {
                $messages[] = "{$check}: [" . implode(', ', $ids) . ']';
            }
        }

        if ($messages !== []) {
            throw new \RuntimeException(
                'Cannot normalize scholarship_refrends.status, ambiguous rows found. '
                . implode('; ', $messages)
                . '. Resolve them manually and re-run the migration.'
            );
        }
    }
};
