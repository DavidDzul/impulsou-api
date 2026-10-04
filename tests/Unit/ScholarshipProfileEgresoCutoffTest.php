<?php

namespace Tests\Unit;

use App\Models\ScholarshipProfile;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * sdd/egresado-status-timing, design D1 (task 1.3, task 4.1).
 *
 * Pure model test, no DB — ScholarshipProfile is instantiated in memory
 * (never persisted), exercising egresoAdministrativoDate() /
 * getEgresoAdministrativoAttribute() / isEgresoReticulaPeriod() directly.
 * Table below mirrors the design's worked-example table verbatim (D1),
 * including the leap/non-leap/month-end overflow cases and the existing
 * test-equivalent row.
 */
class ScholarshipProfileEgresoCutoffTest extends TestCase
{
    private function profile(?string $reticulaEndDate): ScholarshipProfile
    {
        $profile = new ScholarshipProfile();
        $profile->reticula_end_date = $reticulaEndDate;

        return $profile;
    }

    /** @test */
    public function returns_null_when_reticula_end_date_is_null(): void
    {
        $profile = $this->profile(null);

        $this->assertNull($profile->egresoAdministrativoDate());
        $this->assertNull($profile->egreso_administrativo);
    }

    /**
     * @dataProvider workedExamples
     */
    public function test_cutoff_date_matches_the_design_worked_example_table(string $reticulaEndDate, string $expectedCutoff): void
    {
        $profile = $this->profile($reticulaEndDate);

        $cutoff = $profile->egresoAdministrativoDate();

        $this->assertNotNull($cutoff);
        $this->assertSame($expectedCutoff, $cutoff->toDateString());
        $this->assertSame($expectedCutoff, $profile->egreso_administrativo);
    }

    public static function workedExamples(): array
    {
        return [
            'month-end reticula date lands inside month+2' => ['2026-07-31', '2026-09-30'],
            'leap-year Feb 29 target'                       => ['2027-12-31', '2028-02-29'],
            'non-leap Feb 28 target'                         => ['2026-12-29', '2027-02-28'],
            'leap-year Feb 29 input'                         => ['2024-02-29', '2024-04-30'],
            'mid-month input stays at month-end target'      => ['2026-01-31', '2026-03-31'],
            'existing-test-equivalent row (behavior-preserving)' => ['2026-10-01', '2026-12-31'],
        ];
    }

    /** @test */
    public function is_egreso_reticula_period_is_true_only_for_the_cutoff_month(): void
    {
        $profile = $this->profile('2026-07-31'); // cutoff: 2026-09-30

        $this->assertFalse($profile->isEgresoReticulaPeriod(2026, 7));
        $this->assertFalse($profile->isEgresoReticulaPeriod(2026, 8));
        $this->assertTrue($profile->isEgresoReticulaPeriod(2026, 9));
        $this->assertFalse($profile->isEgresoReticulaPeriod(2026, 10));
    }

    /** @test */
    public function is_egreso_reticula_period_is_false_for_the_same_month_number_in_a_different_year(): void
    {
        // Guards the explicit year/month comparison (design D1: NOT
        // isSameMonth(), whose year-sensitivity defaults vary by Carbon
        // version and must not regress silently).
        $profile = $this->profile('2026-07-31'); // cutoff: 2026-09-30

        $this->assertFalse($profile->isEgresoReticulaPeriod(2027, 9));
    }

    /** @test */
    public function is_egreso_reticula_period_is_false_when_reticula_end_date_is_null(): void
    {
        $profile = $this->profile(null);

        $this->assertFalse($profile->isEgresoReticulaPeriod(2026, 9));
    }
}
