<?php

namespace App\Support\Scholarship;

use App\Enums\ScholarshipType;
use BackedEnum;
use Illuminate\Database\Query\Builder;

/**
 * Single source of truth for every TELMEX-specific payability rule
 * (sdd/scholarship-telmex-iu-split, design D1). Consumed by three call
 * sites — `PaymentBatchService::rows()` (SQL), `PaymentReadinessEvaluator`
 * (per-object guard, reached via `BulkPayAction`), and
 * `PaymentBatchService::paidRows()` (export-time exclusion) — so the rule
 * exists exactly once. Final class, static methods, pure: no DB, no
 * framework side effects beyond the query builder mutation in
 * `applyBatchCandidacy()`.
 */
final class TelmexPaymentPolicy
{
    /**
     * Unwraps the enum-cast/raw-string asymmetry (design D5):
     * `PaymentBatchService::rows()` reads `snapshot_scholarship_type` via
     * `DB::table()` (raw string), while `paidRows()` reads it via the
     * `$batch->refrends()` Eloquent relation (enum-cast `ScholarshipType`
     * object, per `ScholarshipRefrend::$casts`). Same trick as
     * `PaymentReadinessEvaluator::statusValue()`.
     */
    private static function type(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * Filter A, per-object shape — consumed by
     * `PaymentReadinessEvaluator::evaluate()`'s `TELMEX_NOT_PAYABLE` guard.
     * A pure TELMEX row without a currently active temporary increase is
     * never a payment-batch candidate (it has nothing payable). Every other
     * type/increase combination is a candidate.
     */
    public static function isBatchCandidate(mixed $type, mixed $increase): bool
    {
        return self::type($type) !== ScholarshipType::TELMEX->value || (float) ($increase ?? 0) > 0;
    }

    /**
     * Filter A, SQL mirror — consumed by `PaymentBatchService::rows()`
     * (a `DB::table()` query, hence the base query-builder type hint, not
     * the Eloquent one). Grouped OR so it combines correctly with the
     * surrounding AND chain of batch-key `where()`s. `NULL > 0` evaluates
     * falsy in SQL, so no `COALESCE` is needed for the increase column.
     */
    public static function applyBatchCandidacy(Builder $query, string $alias): void
    {
        $query->where(function (Builder $where) use ($alias) {
            $where->where("{$alias}.snapshot_scholarship_type", '!=', ScholarshipType::TELMEX->value)
                ->orWhere("{$alias}.snapshot_temporary_increase_amount", '>', 0);
        });
    }

    /**
     * Filter B — export-only exclusion, consumed by
     * `PaymentBatchService::paidRows()`. Reads `total_to_pay` (the same
     * value the bank-file gate validates), NEVER `final_amount` — a TELMEX
     * row can have `final_amount = 0` this period but still owe a positive
     * `total_to_pay` via `amount_pending_from_previous`, which MUST still
     * export.
     */
    public static function isExcludedFromBankFile(mixed $type, string $totalToPay): bool
    {
        return self::type($type) === ScholarshipType::TELMEX->value && (float) $totalToPay <= 0;
    }
}
