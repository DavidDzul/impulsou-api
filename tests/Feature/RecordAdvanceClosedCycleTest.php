<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, characterization: recording an advance for a
 * month after a closed cycle cut is rejected, because
 * GenerateMonthlyRefrendsService::generateFutureForAdvance() calls
 * assertCycleOpen() for every advanced month.
 */
class RecordAdvanceClosedCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function it_rejects_an_advance_for_a_month_after_the_closed_cycle_cut(): void
    {
        $admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $user  = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

        ScholarshipProfile::create([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
            'payment_start_date'  => now()->toDateString(),
            'config_set_at'       => now(),
            'cycle_closed_period' => '2026-10-01',
        ]);

        $origin = ScholarshipRefrend::create([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 9,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);

        $response = $this->actingAs($admin)->postJson(
            "/api/admin/scholarship-refrends/{$origin->id}/advance-payment",
            ['months' => [['year' => 2027, 'month' => 3]], 'cause' => 'Viaje académico']
        );

        $response->assertStatus(422);
        $this->assertSame(0, ScholarshipAdvancePayment::count());
    }
}
