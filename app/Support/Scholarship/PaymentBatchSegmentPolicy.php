<?php

namespace App\Support\Scholarship;

use App\Enums\PaymentBatchType;
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
 * Partition rule (design "Architecture Decisions" > Partition rule):
 *   (r.payment_batch_id IS NULL AND COALESCE(sp.advance_payment_eligible,0) = :eligible)
 *   OR pb.batch_type = :type
 *
 * An UNPAID row (`payment_batch_id IS NULL`) is a candidate for `$type`
 * only when its eligibility flag matches that type's expected eligibility
 * (CERT_UNID expects eligible=true; NORMAL expects eligible=false/missing).
 * A row ALREADY paid is instead a candidate for `$type` only when the batch
 * that paid it has that same `batch_type` — regardless of what
 * `advance_payment_eligible` says NOW. This is what keeps a paid row from
 * flipping to the other type's candidacy (and resurfacing there as a
 * surprise ALREADY_PAID) after a later eligibility flag change.
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

        $query->where(function (Builder $outer) use ($alias, $eligible, $type) {
            $outer->where(function (Builder $unpaid) use ($alias, $eligible) {
                $unpaid->whereNull("{$alias}.payment_batch_id")
                    ->whereRaw('COALESCE(sp.advance_payment_eligible, 0) = ?', [$eligible]);
            })->orWhere('pb.batch_type', $type->value);
        });
    }
}
