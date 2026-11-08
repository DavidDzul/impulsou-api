<?php

namespace App\Support\Scholarship;

use App\Models\ScholarshipRefrend;
use BackedEnum;

/**
 * sdd/descuento-beca-apoyo (design rev 2): read-side split of a refrend's
 * DISPLAYED total into component nets, so every consumer shows the same
 * "Beca neta / Apoyo neta / ..." numbers instead of re-deriving them.
 *
 * Pure and integer-cents: no DB, no writes, no new queries (it only reads
 * columns every caller already selects). total_to_pay, final_amount and the
 * bank file are untouched; this is an additive view.
 *
 *   displayedTotal = month + pending_and_advance
 *   month          = telmex_coverage + D
 *   D              = beca + pago_iu + temporary_increase + apoyo
 *
 * - pending_and_advance (pending + advance, always >= 0) is ledger-backed
 *   carryover money: it is passed through as its own component and NEVER
 *   split, so it can not inflate a net.
 * - Only D is split, proportionally to the NOMINAL components. Shares are
 *   rounded half-up in the order beca, pago_iu, temporary_increase, apoyo;
 *   the last positive-weight component absorbs the remainder, so the sum is
 *   exact. A negative remainder (defensive; not reachable with three
 *   components) is taken from the largest component.
 * - No month net exceeds its own nominal while D <= W (the normal case): each
 *   non-last share loses < 0.5 cent when rounded down, and there are at most
 *   two non-last components, so the last stays below exact + 1 and, being an
 *   integer, <= its nominal. Only a manual final_amount override that raises
 *   the month payable above the discounted gross (D > W) can exceed nominal;
 *   that case keeps the exact-sum invariant and is deliberately not clamped.
 * - The bank-file 0.01 floor arrives inside the displayed total and lands in
 *   the month split (beca / pago_iu), never in pending_and_advance.
 *
 * Duck-typed like RefrendPayableTotal: stdClass rows (DB::table) and
 * Eloquent models both work; missing/null columns count as 0.
 */
final class RefrendNetComponents
{
    private const TYPE_IU = 'IU';

    private const TYPE_TELMEX_IU = 'TELMEX_IU';

    private const TYPE_TELMEX = 'TELMEX';

    /** Split order; also the "last positive-weight absorbs the remainder" order. */
    private const ORDER = ['beca', 'pago_iu', 'temporary_increase', 'apoyo'];

    /**
     * @param array<string,int> $nets    cents per ORDER key
     * @param array<string,int> $nominal cents per ORDER key
     */
    private function __construct(
        private readonly array $nets,
        private readonly array $nominal,
        private readonly int $telmexCoverage,
        private readonly int $pendingAndAdvance,
        private readonly int $total,
    ) {
    }

    /**
     * @param object $row Duck-typed refrend row.
     * @param string|float $displayedTotal The caller's DISPLAYED total_to_pay
     *        (floored in PaymentBatchService::rows(), unfloored elsewhere).
     */
    public static function of(object $row, string|float $displayedTotal): self
    {
        $total             = self::toCents($displayedTotal);
        $pendingAndAdvance = max(0, self::toCents($row->amount_pending_from_previous ?? 0))
            + max(0, self::toCents($row->advance_payment_amount ?? 0));

        return self::build($row, max(0, $total - $pendingAndAdvance), $pendingAndAdvance, $total);
    }

    /**
     * The same month split for an arbitrary month amount in cents, WITHOUT any
     * carryover: the row's pending/advance columns are ignored (so
     * pending_and_advance is 0 and total == monthCents). Used by the
     * Beca/Apoyo bank-file split to weigh a ledger item by the month of the
     * refrend it came from.
     */
    public static function ofMonth(object $row, int $monthCents): self
    {
        $month = max(0, $monthCents);

        return self::build($row, $month, 0, $month);
    }

    private static function build(object $row, int $month, int $pendingAndAdvance, int $total): self
    {
        $covered = min(self::coveredIncludedCents($row), $month);
        $nominal = self::nominalCents($row);

        return new self(
            self::split($month - $covered, $nominal, self::primaryKey($row)),
            $nominal,
            $covered,
            $pendingAndAdvance,
            $total,
        );
    }

    /**
     * Integer-cents twin of toArray() (same keys, same values, no formatting).
     *
     * @return array<string,mixed>
     */
    public function cents(): array
    {
        return [
            'beca'                => $this->nets['beca'],
            'apoyo'               => $this->nets['apoyo'],
            'pago_iu'             => $this->nets['pago_iu'],
            'temporary_increase'  => $this->nets['temporary_increase'],
            'telmex_coverage'     => $this->telmexCoverage,
            'pending_and_advance' => $this->pendingAndAdvance,
            'total'               => $this->total,
            'nominal'             => [
                'beca'               => $this->nominal['beca'],
                'apoyo'              => $this->nominal['apoyo'],
                'pago_iu'            => $this->nominal['pago_iu'],
                'temporary_increase' => $this->nominal['temporary_increase'],
            ],
        ];
    }

    /**
     * Telmex-covered money ACTUALLY included in final_amount, in cents: the
     * coverage FK is set and the refrend is neither a terminal cycle cut nor
     * the automatic EGRESO_RETICULA (none of those $0 paths add it). Mirrors
     * RefrendAmountBreakdown / RecordPaymentSituationAction. Not clamped.
     */
    public static function coveredIncludedCents(object $row): int
    {
        if (($row->snapshot_telmex_coverage_id ?? null) === null) {
            return 0;
        }

        $resolution = $row->resolution_type ?? null;
        if ($resolution === ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA
            || in_array($resolution, ScholarshipRefrend::CYCLE_CUT_RESOLUTIONS, true)) {
            return 0;
        }

        return max(0, self::toCents($row->snapshot_telmex_covered_amount ?? 0));
    }

    /**
     * @return array<string,mixed> every money value is a "0.00" string.
     */
    public function toArray(): array
    {
        return [
            'beca'                => self::money($this->nets['beca']),
            'apoyo'               => self::money($this->nets['apoyo']),
            'pago_iu'             => self::money($this->nets['pago_iu']),
            'temporary_increase'  => self::money($this->nets['temporary_increase']),
            'telmex_coverage'     => self::money($this->telmexCoverage),
            'pending_and_advance' => self::money($this->pendingAndAdvance),
            'total'               => self::money($this->total),
            'nominal'             => [
                'beca'               => self::money($this->nominal['beca']),
                'apoyo'              => self::money($this->nominal['apoyo']),
                'pago_iu'            => self::money($this->nominal['pago_iu']),
                'temporary_increase' => self::money($this->nominal['temporary_increase']),
            ],
        ];
    }

    /**
     * Nominal (pre-discount) component weights in cents, per scholarship type.
     * IU: beca = gross - apoyo - increase. TELMEX_IU: pago_iu = gross - increase.
     * TELMEX: the increase is the whole gross. Null gross falls back to base.
     *
     * @return array<string,int>
     */
    private static function nominalCents(object $row): array
    {
        $gross    = self::toCents($row->snapshot_gross_amount ?? $row->base_amount ?? 0);
        $increase = max(0, self::toCents($row->snapshot_temporary_increase_amount ?? 0));
        $apoyo    = max(0, self::toCents($row->snapshot_monto_apoyo ?? 0));

        $nominal = array_fill_keys(self::ORDER, 0);

        switch (self::type($row)) {
            case self::TYPE_TELMEX_IU:
                $nominal['pago_iu']            = max(0, $gross - $increase);
                $nominal['temporary_increase'] = $increase;
                break;
            case self::TYPE_TELMEX:
                $nominal['temporary_increase'] = $increase;
                break;
            default:
                $nominal['beca']               = max(0, $gross - $apoyo - $increase);
                $nominal['apoyo']              = $apoyo;
                $nominal['temporary_increase'] = $increase;
        }

        return $nominal;
    }

    /**
     * @param array<string,int> $weights
     * @return array<string,int>
     */
    private static function split(int $payable, array $weights, string $primary): array
    {
        $nets = array_fill_keys(self::ORDER, 0);
        if ($payable <= 0) {
            return $nets;
        }

        $positive = array_keys(array_filter($weights, static fn (int $w): bool => $w > 0));
        if ($positive === []) {
            $nets[$primary] = $payable;

            return $nets;
        }

        $totalWeight = array_sum($weights);
        $last        = end($positive);
        $allocated   = 0;

        foreach ($positive as $key) {
            if ($key === $last) {
                continue;
            }
            $nets[$key] = intdiv(2 * $payable * $weights[$key] + $totalWeight, 2 * $totalWeight);
            $allocated += $nets[$key];
        }

        $remainder = $payable - $allocated;
        if ($remainder >= 0) {
            $nets[$last] = $remainder;

            return $nets;
        }

        // Defensive: rounding over-allocated. Take the excess from the largest share.
        $largest = array_keys($nets, max($nets), true)[0];
        $nets[$largest] += $remainder;

        return $nets;
    }

    /** Where W = 0 sends the payable: IU beca, TELMEX_IU pago_iu, TELMEX increase. */
    private static function primaryKey(object $row): string
    {
        return match (self::type($row)) {
            self::TYPE_TELMEX_IU => 'pago_iu',
            self::TYPE_TELMEX    => 'temporary_increase',
            default              => 'beca',
        };
    }

    /** Unwraps the enum-cast (Eloquent) vs raw-string (DB::table) asymmetry. */
    private static function type(object $row): string
    {
        $type = $row->snapshot_scholarship_type ?? self::TYPE_IU;

        return $type instanceof BackedEnum ? (string) $type->value : (string) $type;
    }

    public static function toCents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
