<?php

namespace Tests\Unit;

use App\Models\ScholarshipRefrend;
use App\Services\Scholarship\CutAnchor;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * sdd/retenciones-pendientes, PR2: value object describing the month from
 * which advance payments are cancelled (a refrend for cuts/egreso, the
 * current month for user deactivation).
 */
class CutAnchorTest extends TestCase
{
    /** @test */
    public function from_refrend_copies_user_period_and_refrend_id(): void
    {
        $refrend = new ScholarshipRefrend();
        $refrend->id           = 55;
        $refrend->user_id      = 9;
        $refrend->period_year  = 2026;
        $refrend->period_month = 11;

        $anchor = CutAnchor::fromRefrend($refrend);

        $this->assertSame(9, $anchor->userId);
        $this->assertSame(2026, $anchor->year);
        $this->assertSame(11, $anchor->month);
        $this->assertSame(55, $anchor->cutRefrendId);
    }

    /** @test */
    public function for_deactivation_uses_the_month_of_now_and_has_no_refrend(): void
    {
        $anchor = CutAnchor::forDeactivation(7, Carbon::create(2027, 2, 15));

        $this->assertSame(7, $anchor->userId);
        $this->assertSame(2027, $anchor->year);
        $this->assertSame(2, $anchor->month);
        $this->assertNull($anchor->cutRefrendId);
    }
}
