<?php

namespace Tests\Unit;

use App\Support\Scholarship\RefrendNetComponents;
use Tests\TestCase;

/**
 * sdd/descuento-beca-apoyo: seeded property loop over RefrendNetComponents.
 * The generator rebuilds final_amount/total independently of the VO (same
 * formula as ScholarshipCalculationService::calculateFinalAmount, plus the
 * carryover terms and the bank-file 0.01 floor), then asserts the invariants
 * of design rev 2. Deterministic: fixed seed, no DB.
 */
class RefrendNetComponentsPropertyTest extends TestCase
{
    private const CASES = 2000;

    private const SEED = 20261108;

    private const COMPONENT_KEYS = ['beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage', 'pending_and_advance'];

    private function pick(array $values): mixed
    {
        return $values[mt_rand(0, count($values) - 1)];
    }

    /** Random cents, with a good share of exact zeros. */
    private function randomCents(int $max, int $zeroOneIn = 4): int
    {
        return mt_rand(1, $zeroOneIn) === 1 ? 0 : mt_rand(0, $max);
    }

    /** @return array{0: object, 1: string, 2: array<string,mixed>} row, displayed total, case meta */
    private function generateCase(): array
    {
        $type     = $this->pick(['IU', 'IU', 'TELMEX_IU', 'TELMEX']);
        $increase = $this->randomCents(80000);
        $apoyo    = $this->randomCents(120000);
        $primary  = $this->randomCents(400000);

        // Legacy rows without a gross snapshot: the VO falls back to base_amount,
        // so the generator keeps final_amount consistent with that gross.
        $nullGross = mt_rand(1, 6) === 1;
        if ($nullGross) {
            $increase = 0;
            $apoyo    = 0;
        }

        // Payable gross per type (ScholarshipCalculationService::snapshotFor).
        $gross = match (true) {
            $nullGross            => $primary,
            $type === 'IU'        => $primary + $apoyo + $increase,
            $type === 'TELMEX_IU' => $primary + $increase,
            default               => $increase,
        };

        $academicPct = $this->pick([0, 0, 5, 10, 15, 30]);
        $penaltyPct  = $this->pick([0, 0, 0, 10, 25, 33.33, 50, 100]);
        $hasCoverage = $type !== 'IU' && mt_rand(1, 2) === 1;
        $covered     = $hasCoverage ? mt_rand(0, 300000) : 0;
        $resolution  = $this->pick([null, null, null, null, 'EGRESADO', 'BAJA_DEFINITIVA', 'EGRESO_RETICULA']);
        $isCut       = $resolution !== null;

        $grossAmount = $gross / 100;
        $base        = round($grossAmount * (1 - $academicPct / 100), 2);
        $discount    = round($base * ($penaltyPct / 100), 2);
        $iuFinal     = max(0, round($base - $discount, 2));

        if ($isCut) {
            $final = 0.0;
        } else {
            $final = $covered > 0 ? round($iuFinal + $covered / 100, 2) : $iuFinal;
        }

        $pending = $this->randomCents(500000, 3);
        $advance = $this->randomCents(500000, 3);
        $total   = round($final + $pending / 100 + $advance / 100, 2);

        $floored = false;
        if ($type !== 'TELMEX' && (float) $total === 0.0) {
            $total   = 0.01;
            $floored = true;
        }

        $row = (object) [
            'snapshot_scholarship_type'          => $type,
            'base_amount'                        => number_format($primary / 100, 2, '.', ''),
            'snapshot_gross_amount'              => $nullGross ? null : number_format($grossAmount, 2, '.', ''),
            'snapshot_monto_apoyo'               => number_format($apoyo / 100, 2, '.', ''),
            'snapshot_temporary_increase_amount' => $increase > 0 ? number_format($increase / 100, 2, '.', '') : null,
            'snapshot_telmex_coverage_id'        => $hasCoverage ? 1 : null,
            'snapshot_telmex_covered_amount'     => number_format($covered / 100, 2, '.', ''),
            'resolution_type'                    => $resolution,
            'final_amount'                       => number_format($final, 2, '.', ''),
            'amount_pending_from_previous'       => number_format($pending / 100, 2, '.', ''),
            'advance_payment_amount'             => number_format($advance / 100, 2, '.', ''),
        ];

        return [$row, number_format($total, 2, '.', ''), [
            'type'       => $type,
            'floored'    => $floored,
            'pending'    => $pending,
            'advance'    => $advance,
            'cut'        => $isCut,
        ]];
    }

    private function cents(string $value): int
    {
        return (int) round((float) $value * 100);
    }

    /** @test */
    public function invariants_hold_over_seeded_random_cases(): void
    {
        mt_srand(self::SEED);

        for ($i = 0; $i < self::CASES; $i++) {
            [$row, $total, $meta] = $this->generateCase();
            $ctx = sprintf('case %d type=%s total=%s', $i, $meta['type'], $total);

            $b = RefrendNetComponents::of($row, $total)->toArray();

            // Exact cent sum, including carryover, equal to the displayed total.
            $sum = 0;
            foreach (self::COMPONENT_KEYS as $key) {
                $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $b[$key], "$ctx $key format");
                $sum += $this->cents($b[$key]);
            }
            $this->assertSame($this->cents($total), $sum, "$ctx sum");
            $this->assertSame($total, $b['total'], "$ctx total echo");

            // Carryover is passed through untouched and never split.
            $this->assertSame($meta['pending'] + $meta['advance'], $this->cents($b['pending_and_advance']), "$ctx carryover");

            // Nets are never negative.
            foreach (self::COMPONENT_KEYS as $key) {
                $this->assertGreaterThanOrEqual(0, $this->cents($b[$key]), "$ctx $key >= 0");
            }

            // Components foreign to the type stay at zero.
            if ($meta['type'] === 'TELMEX') {
                $this->assertSame('0.00', $b['beca'], "$ctx telmex beca");
                $this->assertSame('0.00', $b['apoyo'], "$ctx telmex apoyo");
                $this->assertSame('0.00', $b['pago_iu'], "$ctx telmex pago_iu");
            }
            if ($meta['type'] === 'TELMEX_IU') {
                $this->assertSame('0.00', $b['beca'], "$ctx telmex_iu beca");
                $this->assertSame('0.00', $b['apoyo'], "$ctx telmex_iu apoyo");
            }
            if ($meta['type'] === 'IU') {
                $this->assertSame('0.00', $b['pago_iu'], "$ctx iu pago_iu");
                $this->assertSame('0.00', $b['telmex_coverage'], "$ctx iu coverage");
            }

            $this->assertSplitIsProportional($b, $meta, $ctx);
        }
    }

    /** Month nets stay within 1 cent of the exact share and below nominal (no override, no floor). */
    private function assertSplitIsProportional(array $b, array $meta, string $ctx): void
    {
        if ($meta['floored']) {
            return;
        }

        $nominal = [];
        $weight  = 0;
        foreach (['beca', 'pago_iu', 'temporary_increase', 'apoyo'] as $key) {
            $nominal[$key] = $this->cents($b['nominal'][$key]);
            $weight += $nominal[$key];
        }

        $payable = $this->cents($b['total']) - $this->cents($b['pending_and_advance']) - $this->cents($b['telmex_coverage']);
        if ($weight === 0 || $payable === 0) {
            return;
        }

        $positive = array_keys(array_filter($nominal, static fn (int $w): bool => $w > 0));
        $last     = end($positive);

        foreach ($positive as $key) {
            $net = $this->cents($b[$key]);

            if ($key !== $last) {
                // |net - D*w/W| <= 0.5 cent  <=>  |net*W - D*w| <= W/2 (compare doubled).
                $this->assertLessThanOrEqual($weight, abs(2 * ($net * $weight - $payable * $nominal[$key])), "$ctx $key share");
            }

            // No month component may exceed its own nominal (no manual override here).
            $this->assertLessThanOrEqual($nominal[$key], $net, "$ctx $key <= nominal");
        }
    }

    /** @test */
    public function the_property_generator_is_deterministic(): void
    {
        mt_srand(self::SEED);
        $first = $this->generateCase();
        mt_srand(self::SEED);
        $second = $this->generateCase();

        $this->assertEquals($first, $second);
    }
}
