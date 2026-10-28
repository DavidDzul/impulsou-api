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
     * A pure TELMEX row is a candidate only when it has something payable:
     * a currently active temporary increase, an active Telmex coverage
     * (sdd/telmex-cobertura-iu, design D1), OR owed money — pending from
     * previous periods plus advance > 0 (sdd/telmex-pendiente-lote). A pure
     * TELMEX row with none of the four has nothing payable and is never a
     * candidate. Every non-TELMEX type is always a candidate.
     *
     * $coverageId mirrors `ScholarshipRefrend::snapshot_telmex_coverage_id`:
     * any non-null value (int or numeric string — both shapes occur across
     * call sites, same D5 asymmetry as `$type`) means the refrend's period
     * fell inside an active coverage window at generation time, so the
     * covered amount already embedded in `final_amount` (PR1, design D3) is
     * real money IU owes — candidacy must follow.
     *
     * $pending / $advance mirror `amount_pending_from_previous` and
     * `advance_payment_amount` (null-safe, int/float/decimal-string alike);
     * omitting them means "owes nothing", so older callers are unchanged.
     */
    public static function isBatchCandidate(
        mixed $type,
        mixed $increase,
        mixed $coverageId = null,
        mixed $pending = null,
        mixed $advance = null
    ): bool {
        return self::type($type) !== ScholarshipType::TELMEX->value
            || (float) ($increase ?? 0) > 0
            || $coverageId !== null
            || ((float) ($pending ?? 0) + (float) ($advance ?? 0)) > 0;
    }

    /**
     * Filter A, SQL mirror — consumed by `PaymentBatchService::rows()`
     * (a `DB::table()` query, hence the base query-builder type hint, not
     * the Eloquent one). Grouped OR so it combines correctly with the
     * surrounding AND chain of batch-key `where()`s. `NULL > 0` evaluates
     * falsy in SQL, so no `COALESCE` is needed for the increase column.
     * `orWhereNotNull` (sdd/telmex-cobertura-iu, design) mirrors the
     * per-object `$coverageId !== null` check above; the owed-money branch
     * (sdd/telmex-pendiente-lote) mirrors `$pending + $advance > 0` and
     * needs `COALESCE` because the sum of two NULLs is NULL.
     */
    public static function applyBatchCandidacy(Builder $query, string $alias): void
    {
        $query->where(function (Builder $where) use ($alias) {
            $where->where("{$alias}.snapshot_scholarship_type", '!=', ScholarshipType::TELMEX->value)
                ->orWhere("{$alias}.snapshot_temporary_increase_amount", '>', 0)
                ->orWhereNotNull("{$alias}.snapshot_telmex_coverage_id")
                ->orWhereRaw(self::owedSql($alias) . ' > 0');
        });
    }

    /**
     * Exact SQL negation of `applyBatchCandidacy()`: pure TELMEX rows that
     * are NOT payment-batch candidates (no active increase, no coverage,
     * nothing owed). Consumed by `GenerationPaymentReport::noIuPaymentRows()`
     * so the NO_IU_PAYMENT classification can never drift from candidacy.
     */
    public static function applyNonCandidacy(Builder $query, string $alias): void
    {
        $query->where("{$alias}.snapshot_scholarship_type", ScholarshipType::TELMEX->value)
            ->where(fn (Builder $where) => $where->whereNull("{$alias}.snapshot_temporary_increase_amount")
                ->orWhere("{$alias}.snapshot_temporary_increase_amount", '<=', 0))
            ->whereNull("{$alias}.snapshot_telmex_coverage_id")
            ->whereRaw(self::owedSql($alias) . ' <= 0');
    }

    /**
     * Owed money for this refrend (pending from previous periods plus
     * advance). `$alias` is an internal literal, never user input.
     */
    private static function owedSql(string $alias): string
    {
        return "(COALESCE({$alias}.amount_pending_from_previous, 0) + COALESCE({$alias}.advance_payment_amount, 0))";
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
