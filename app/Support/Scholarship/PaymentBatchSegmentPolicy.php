<?php

namespace App\Support\Scholarship;

use App\Enums\PaymentBatchType;
use App\Enums\PaymentMethod;
use Illuminate\Database\Query\Builder;

/**
 * Filter D (sdd/pagos-lote-pago-adelantado, design "Filter location"): the
 * SQL partition that splits candidacy between NORMAL and CERT_UNID batches
 * for the same campus/period, while freezing an already-paid row to the
 * type of the batch that actually paid it. Mirrors
 * `TelmexPaymentPolicy::applyBatchCandidacy`'s static-policy-class pattern
 * (Filter A) so this partition rule exists exactly once and can be
 * unit-tested independently — in practice it is exercised end-to-end
 * through `PaymentBatchServiceTest`, the same convention Filter A/C use.
 *
 * Partition rule (sdd/cert-unid-historial, spec R4; supersedes the original
 * batch-only freeze):
 *   (frozen IS NULL AND COALESCE(sp.advance_payment_eligible,0) = :eligible)
 *   OR frozen = :type
 * where `frozen` is `frozenTypeSql()`: the segment a PAID row is frozen to
 * (batch-paid -> pb.batch_type; cheque-paid -> paid_batch_type stamped at
 * payment time), NULL for everything still following the live flag.
 *
 * An UNPAID row (frozen NULL) is a candidate for `$type` only when its
 * eligibility flag matches that type's expected eligibility (CERT_UNID
 * expects eligible=true; NORMAL expects eligible=false/missing), so the
 * CERT_UNID batch keeps paying ONLY the flagged ("pago adelantado")
 * becarios and a mid-month flag correction is followed until payment. A PAID
 * row is a candidate for `$type` only when its frozen type matches,
 * regardless of what the flag says NOW. A legacy cheque row with no stamp,
 * or a stale stamp whose paid_payment_method is not CHEQUE, yields NULL and
 * falls back to the current flag.
 *
 * Joins added here (not `whereExists` subqueries): `scholarship_profiles
 * .user_id` is UNIQUE (2026_05_13 migration) and `scholarship_payment_batches
 * .id` is a PK, so neither LEFT JOIN can duplicate a row from `$alias`.
 * `COALESCE` usage mirrors `RefrendBulkQueryService.php:115`'s exact
 * rationale: a becario with no `scholarship_profiles` row at all (LEFT JOIN
 * NULL) must read as "not eligible", i.e. NORMAL.
 */
final class PaymentBatchSegmentPolicy
{
    public static function applyBatchCandidacy(Builder $query, string $alias, PaymentBatchType $type): void
    {
        $query
            ->leftJoin('scholarship_profiles as sp', 'sp.user_id', '=', "{$alias}.user_id")
            ->leftJoin('scholarship_payment_batches as pb', 'pb.id', '=', "{$alias}.payment_batch_id");

        $eligible = $type === PaymentBatchType::CERT_UNID ? 1 : 0;

        [$frozen, $frozenBindings] = self::frozenTypeSql($alias);

        $query->whereRaw(
            "(({$frozen} IS NULL AND COALESCE(sp.advance_payment_eligible, 0) = ?) OR {$frozen} = ?)",
            array_merge($frozenBindings, [$eligible], $frozenBindings, [$type->value])
        );
    }

    /**
     * The single SQL definition of "the segment this row is frozen to".
     * Requires the `pb` join (scholarship_payment_batches on payment_batch_id).
     * Shared with RefrendBulkQueryService so both readers cannot drift.
     *
     * @return array{0: string, 1: array<int, string>} SQL expression and its bindings
     */
    public static function frozenTypeSql(string $alias): array
    {
        return [
            "(CASE WHEN {$alias}.payment_batch_id IS NOT NULL THEN pb.batch_type"
            . " WHEN {$alias}.paid_payment_method = ? THEN {$alias}.paid_batch_type END)",
            [PaymentMethod::CHEQUE->value],
        ];
    }
}
