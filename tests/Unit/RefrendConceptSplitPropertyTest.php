<?php

namespace Tests\Unit;

use App\Support\Scholarship\RefrendConceptSplit;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo (task 1.6, spec R1): seeded property loop.
 * For every generated row: beca + apoyo == displayed total EXACTLY, both >= 0,
 * the floor cent lands in Beca, and the carryover Apoyo never exceeds the
 * carryover amount. Deterministic: fixed seed, no DB.
 */
class RefrendConceptSplitPropertyTest extends TestCase
{
    private const CASES = 2000;

    private const SEED = 20261109;

    private function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    private function randomCents(int $max, int $zeroOneIn = 4): int
    {
        return mt_rand(1, $zeroOneIn) === 1 ? 0 : mt_rand(0, $max);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** @test */
    public function the_split_always_adds_up_to_the_displayed_total_and_never_goes_negative(): void
    {
        mt_srand(self::SEED);

        $floorCases = 0;
        $scaledCases = 0;

        for ($i = 0; $i < self::CASES; $i++) {
            $type      = $this->pick(['IU', 'IU', 'TELMEX_IU', 'TELMEX']);
            $increase  = $this->randomCents(80000);
            $apoyo     = $this->randomCents(120000);
            $primary   = $this->randomCents(400000);
            $covered   = $type === 'IU' ? 0 : $this->randomCents(300000);
            $hasFk     = $type !== 'IU' && mt_rand(1, 4) > 1;
            $monthCents = $this->randomCents(700000, 5);

            $row = (object) [
                'snapshot_scholarship_type'          => $type,
                'base_amount'                        => $this->money($primary),
                'snapshot_gross_amount'              => mt_rand(1, 6) === 1 ? null : $this->money($primary + $apoyo + $increase),
                'snapshot_monto_apoyo'               => $this->money($apoyo),
                'snapshot_temporary_increase_amount' => $this->money($increase),
                'snapshot_telmex_coverage_id'        => $hasFk ? 7 : null,
                'snapshot_telmex_covered_amount'     => $this->money($covered),
                'resolution_type'                    => $this->pick([null, null, null, 'EGRESO_RETICULA', 'EGRESADO']),
                'final_amount'                       => $this->money($monthCents),
            ];

            // Carryover: a ledger split that may be empty, weightless or != target.
            $pending = $this->randomCents(400000, 3);
            $advance = $this->randomCents(400000, 3);
            $target  = $pending + $advance;
            $ledger  = RefrendConceptSplit::of($this->randomCents(300000, 3), $this->randomCents(300000, 3));
            $carry   = $ledger->scaleTo($target, RefrendConceptSplit::fallbackWeights($row));

            $this->assertSame($target, $carry->totalCents(), "case $i: carryover must equal its column");
            $this->assertLessThanOrEqual($target, $carry->apoyoCents(), "case $i: carryover apoyo <= T");
            $this->assertGreaterThanOrEqual(0, $carry->becaCents());
            if ($ledger->totalCents() !== $target && $target > 0) {
                $scaledCases++;
            }

            $raw       = $monthCents + $target;
            $displayed = ($raw === 0 && mt_rand(1, 2) === 1) ? 1 : $raw;
            $split     = RefrendConceptSplit::forRow($row, $raw, $displayed, $carry);

            $this->assertSame($displayed, $split->totalCents(), "case $i: beca + apoyo must equal displayed");
            $this->assertGreaterThanOrEqual(0, $split->becaCents(), "case $i: beca >= 0");
            $this->assertGreaterThanOrEqual(0, $split->apoyoCents(), "case $i: apoyo >= 0");

            if ($raw === 0 && $displayed === 1) {
                $floorCases++;
                $this->assertSame([1, 0], [$split->becaCents(), $split->apoyoCents()], "case $i: floor goes to Beca");
            }
        }

        $this->assertGreaterThan(15, $floorCases, 'the generator must exercise the floor');
        $this->assertGreaterThan(500, $scaledCases, 'the generator must exercise S != T');
    }

    /** @test */
    public function proportional_is_exact_for_random_amounts_and_weights(): void
    {
        mt_srand(self::SEED + 1);

        for ($i = 0; $i < self::CASES; $i++) {
            $cents = mt_rand(0, 5000000);
            $w     = RefrendConceptSplit::of($this->randomCents(500000), $this->randomCents(500000));
            $s     = RefrendConceptSplit::proportional($cents, $w);

            $this->assertSame($cents, $s->totalCents());
            $this->assertGreaterThanOrEqual(0, $s->becaCents());
            $this->assertGreaterThanOrEqual(0, $s->apoyoCents());
        }
    }
}
