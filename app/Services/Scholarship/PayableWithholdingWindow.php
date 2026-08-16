<?php

namespace App\Services\Scholarship;

use App\Models\ScholarshipWithholding;
use Illuminate\Support\Collection;

/**
 * Defines which RETENIDA withholding rows are payable for a given refrendo
 * period: origin period 1-3 months back (inclusive), top 2 most recent when
 * more than 2 fall in-window. Query-time only — no row is ever mutated here.
 *
 * Eligibility is a SET operation, not a per-row predicate: the top-2 cap
 * depends on a row's peers, so "is entry X payable?" is undecidable in
 * isolation. Both HTTP-layer validation and the Action-layer re-check
 * compute this same set and test membership, instead of duplicating the
 * ranking logic per call site.
 *
 * `selectPayable()` reads `period_year`/`period_month` via `data_get()` so
 * it accepts Eloquent models (controller/validation call sites) AND
 * `stdClass` rows from the query builder (bulk service) without two
 * overloads or hydrating models in a bulk path.
 */
final class PayableWithholdingWindow
{
    /** How many months back (inclusive) from the current period a row may originate from and still be payable. */
    public const LOOKBACK_MONTHS = 3;

    /** Max number of in-window rows treated as payable, most recent first. */
    public const MAX_PAYABLE = 2;

    /** year*12 + month — total order over periods, rollover-safe (e.g. Dec 2025 -> Jan 2026). */
    public static function absoluteMonth(int $year, int $month): int
    {
        return $year * 12 + $month;
    }

    /**
     * Offset = current - origin; payable window is 0..LOOKBACK_MONTHS.
     * offset = 0 is unreachable in practice (self-liquidation guard) but the
     * window is defined as total (0..3) so this helper needs no caller-side
     * precondition.
     */
    public static function isWithinWindow(int $curY, int $curM, int $origY, int $origM): bool
    {
        $offset = self::absoluteMonth($curY, $curM) - self::absoluteMonth($origY, $origM);

        return $offset >= 0 && $offset <= self::LOOKBACK_MONTHS;
    }

    /** [minAbsoluteMonth, maxAbsoluteMonth] for SQL pre-filtering. */
    public static function absoluteBounds(int $curY, int $curM): array
    {
        $curAbs = self::absoluteMonth($curY, $curM);

        return [$curAbs - self::LOOKBACK_MONTHS, $curAbs];
    }

    /**
     * PURE. Window-filters, sorts by period DESC, takes MAX_PAYABLE.
     * Accepts models, arrays or stdClass rows (data_get).
     *
     * @param  iterable<mixed>  $rows
     */
    public static function selectPayable(iterable $rows, int $curY, int $curM): Collection
    {
        return collect($rows)
            ->filter(function ($row) use ($curY, $curM) {
                return self::isWithinWindow(
                    $curY,
                    $curM,
                    (int) data_get($row, 'period_year'),
                    (int) data_get($row, 'period_month')
                );
            })
            ->sortByDesc(function ($row) {
                return self::absoluteMonth(
                    (int) data_get($row, 'period_year'),
                    (int) data_get($row, 'period_month')
                );
            })
            ->take(self::MAX_PAYABLE)
            ->values();
    }

    /** The ONLY DB-touching method: pending rows of $userId -> payable ids. */
    public static function payableIdsForUser(int $userId, int $curY, int $curM): array
    {
        [$minAbs, $maxAbs] = self::absoluteBounds($curY, $curM);

        $rows = ScholarshipWithholding::where('user_id', $userId)
            ->pending()
            ->whereRaw('(period_year * 12 + period_month) between ? and ?', [$minAbs, $maxAbs])
            ->get();

        return self::selectPayable($rows, $curY, $curM)->pluck('id')->all();
    }
}
