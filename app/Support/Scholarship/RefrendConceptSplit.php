<?php

namespace App\Support\Scholarship;

/**
 * sdd/archivo-pago-beca-apoyo: a money amount (or a pair of weights) split
 * into the two bank-file concepts, Beca and Apoyo, in integer cents.
 *
 * Pure: no DB, no writes. Rounding is deterministic: Beca is rounded half-up
 * and Apoyo is always the remainder, so beca + apoyo == the input exactly.
 */
final class RefrendConceptSplit
{
    /** Refrend columns every consumer (and the carryover resolver) must select. */
    public const COLUMNS = [
        'id',
        'base_amount',
        'final_amount',
        'snapshot_scholarship_type',
        'snapshot_gross_amount',
        'snapshot_monto_apoyo',
        'snapshot_temporary_increase_amount',
        'snapshot_telmex_coverage_id',
        'snapshot_telmex_covered_amount',
        'resolution_type',
        'amount_pending_from_previous',
        'advance_payment_amount',
    ];

    private function __construct(
        private readonly int $beca,
        private readonly int $apoyo,
    ) {
    }

    public static function of(int $beca, int $apoyo): self
    {
        return new self(max(0, $beca), max(0, $apoyo));
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    /**
     * Splits $cents by the ratio of $weights (Beca half-up, Apoyo remainder).
     * Zero weights use $fallback (one level, never recursive); with no usable
     * weights at all the whole amount is Beca.
     */
    public static function proportional(int $cents, self $weights, ?self $fallback = null): self
    {
        if ($cents <= 0) {
            return self::zero();
        }

        if ($weights->totalCents() <= 0) {
            $weights = $fallback !== null && $fallback->totalCents() > 0 ? $fallback : self::of(1, 0);
        }

        $total = $weights->totalCents();
        $beca  = intdiv(2 * $cents * $weights->beca + $total, 2 * $total);

        return new self($beca, $cents - $beca);
    }

    /**
     * One month's payable split into Beca / Apoyo (no carryover).
     *
     *   Beca  = net beca + pago_iu + Beca share of (increase + Telmex coverage)
     *   Apoyo = net apoyo + Apoyo share of (increase + Telmex coverage)
     *
     * The shares use the becario's concept weights, so an unclamped Telmex
     * coverage yields exactly monto_apoyo as Apoyo and a clamped one is scaled.
     */
    public static function month(object $row, int $monthCents): self
    {
        $n       = RefrendNetComponents::ofMonth($row, $monthCents)->cents();
        $weights = self::conceptWeights($row);
        $shared  = self::proportional($n['temporary_increase'] + $n['telmex_coverage'], $weights);

        return new self(
            $n['beca'] + $n['pago_iu'] + $shared->beca,
            $n['apoyo'] + $shared->apoyo,
        );
    }

    /**
     * The row's full Beca / Apoyo split.
     *
     * $rawCents is the total BEFORE the bank-file 0.01 floor and $displayedCents
     * the total AFTER it; the floor cent (0 or 1) is added to Beca only. The
     * month is $rawCents minus the carryover, split by month(); $carryover is
     * the already-resolved split of pending + advance (CarryoverConceptResolver).
     * beca + apoyo == $displayedCents always.
     */
    public static function forRow(object $row, int $rawCents, int $displayedCents, self $carryover): self
    {
        $displayed = max(0, $displayedCents);
        $raw       = min(max(0, $rawCents), $displayed);
        $floor     = $displayed - $raw;

        if ($carryover->totalCents() > $raw) {
            $apoyo     = min($carryover->apoyo, $raw);
            $carryover = new self($raw - $apoyo, $apoyo);
        }

        $month = self::month($row, $raw - $carryover->totalCents());

        return new self(
            $month->beca + $carryover->beca + $floor,
            $month->apoyo + $carryover->apoyo,
        );
    }

    /**
     * Rescales this ledger split to the column amount $target (D4): Apoyo is
     * round-half-up of its share, capped at the target; the residual is Beca.
     * An empty ledger split uses $fallback weights, then 100% Beca.
     */
    public function scaleTo(int $target, ?self $fallback = null): self
    {
        if ($target <= 0) {
            return self::zero();
        }

        $sum = $this->totalCents();
        if ($sum <= 0) {
            return self::proportional($target, $fallback ?? self::zero());
        }

        $apoyo = min($target, intdiv(2 * $this->apoyo * $target + $sum, 2 * $sum));

        return new self($target - $apoyo, $apoyo);
    }

    /**
     * Weights for a ledger item whose origin/child is missing or weightless
     * (D5): the row's own month split, else its concept weights, else empty
     * (proportional() then sends the amount to Beca).
     */
    public static function fallbackWeights(object $row): self
    {
        $final = RefrendNetComponents::toCents($row->final_amount ?? 0);
        if ($final > 0) {
            return self::month($row, $final);
        }

        return self::conceptWeights($row);
    }

    /**
     * The becario's nominal Beca:Apoyo weights (cents).
     *  - IU: nominal beca (gross - apoyo - increase) : snapshot_monto_apoyo.
     *  - TELMEX / TELMEX_IU: (covered - apoyo) : apoyo, from the snapshot
     *    columns regardless of the coverage FK (the snapshot is always built).
     * Both 0 means "no weights" (proportional() then sends everything to Beca).
     */
    public static function conceptWeights(object $row): self
    {
        $apoyo = max(0, RefrendNetComponents::toCents($row->snapshot_monto_apoyo ?? 0));

        if (RefrendNetComponents::type($row) !== 'IU') {
            $covered = RefrendNetComponents::toCents($row->snapshot_telmex_covered_amount ?? 0);

            return new self(max(0, $covered - $apoyo), $apoyo);
        }

        $nominal = RefrendNetComponents::ofMonth($row, 0)->cents()['nominal'];

        return new self($nominal['beca'], $nominal['apoyo']);
    }

    public function becaCents(): int
    {
        return $this->beca;
    }

    public function apoyoCents(): int
    {
        return $this->apoyo;
    }

    public function totalCents(): int
    {
        return $this->beca + $this->apoyo;
    }

    /** @return array{beca: string, apoyo: string} */
    public function toArray(): array
    {
        return [
            'beca'  => self::money($this->beca),
            'apoyo' => self::money($this->apoyo),
        ];
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
