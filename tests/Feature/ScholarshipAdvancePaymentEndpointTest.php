<?php

namespace Tests\Feature;

use App\Actions\Scholarship\RecordAdvancePaymentAction;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature coverage for POST /api/admin/scholarship-refrends/{refrend}/advance-payment
 * (design "Endpoint" — same permission gate as recordSituation, no dedicated
 * permission middleware beyond the group's auth:sanctum + user_type:ADMIN).
 */
class ScholarshipAdvancePaymentEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'user_type' => 'ADMIN',
            'active'    => true,
        ]);

        Carbon::setTestNow(Carbon::create(2026, 9, 15));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeOriginRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        ScholarshipProfile::create([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => null,
            'reticula_end_date'   => null,
            'payment_start_date'  => now()->toDateString(),
        ]);

        return ScholarshipRefrend::create(array_merge([
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
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function url(int $refrendId): string
    {
        return "/api/admin/scholarship-refrends/{$refrendId}/advance-payment";
    }

    /** @test */
    public function it_returns_422_when_more_than_the_cap_is_requested(): void
    {
        $origin = $this->makeOriginRefrend();

        // Built from the constant (not hardcoded) so raising the cap later
        // doesn't silently turn this into a false negative — origin is
        // always 2026-09 (makeOriginRefrend's default), so this walks
        // forward MAX_ADVANCED_MONTHS + 1 months from there.
        $months = [];
        $year   = 2026;
        $month  = 9;
        for ($i = 0; $i < RecordAdvancePaymentAction::MAX_ADVANCED_MONTHS + 1; $i++) {
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
            $months[] = ['year' => $year, 'month' => $month];
        }

        $response = $this->actingAs($this->admin)->postJson($this->url($origin->id), [
            'months' => $months,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_returns_422_on_duplicate_months_in_the_same_request(): void
    {
        $origin = $this->makeOriginRefrend();

        $response = $this->actingAs($this->admin)->postJson($this->url($origin->id), [
            'months' => [
                ['year' => 2026, 'month' => 12],
                ['year' => 2026, 'month' => 12],
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_returns_422_when_a_requested_month_is_not_future(): void
    {
        $origin = $this->makeOriginRefrend();

        $response = $this->actingAs($this->admin)->postJson($this->url($origin->id), [
            'months' => [
                ['year' => 2026, 'month' => 9], // same as origin's own period
            ],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_returns_200_on_a_valid_request_and_creates_the_batch(): void
    {
        $origin = $this->makeOriginRefrend();

        $response = $this->actingAs($this->admin)->postJson($this->url($origin->id), [
            'months' => [
                ['year' => 2026, 'month' => 11],
                ['year' => 2026, 'month' => 12],
            ],
            'cause' => 'Viaje académico',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['res' => true]);
        $this->assertSame(2, $response->json('data.months_count'));

        $this->assertSame(1, ScholarshipAdvancePayment::where('origin_refrend_id', $origin->id)->count());
        $this->assertSame(2, ScholarshipAdvancePaymentMonth::count());
    }

    /** @test */
    public function a_db_level_unique_violation_from_a_genuine_race_surfaces_as_422_not_500(): void
    {
        $origin = $this->makeOriginRefrend();

        $raceListener = ScholarshipAdvancePaymentMonth::creating(function ($model) use ($origin) {
            if ((int) $model->period_year === 2026 && (int) $model->period_month === 12) {
                $competingRefrend = ScholarshipRefrend::create([
                    'user_id'       => $origin->user_id,
                    'period_year'   => 2026,
                    'period_month'  => 12,
                    'refrend_type'  => RefrendType::RETENCION->value,
                    'base_amount'   => 100,
                    'final_amount'  => 100,
                    'snapshot_name' => 'Racing Refrend',
                ]);
                $competingHeader = ScholarshipAdvancePayment::create([
                    'user_id'             => $origin->user_id,
                    'origin_refrend_id'   => $competingRefrend->id,
                    'origin_period_year'  => 2026,
                    'origin_period_month' => 12,
                    'months_count'        => 1,
                    'total_amount'        => '100.00',
                    'status'              => 'ACTIVE',
                ]);
                DB::table('scholarship_advance_payment_months')->insert([
                    'advance_payment_id' => $competingHeader->id,
                    'user_id'            => $origin->user_id,
                    'period_year'        => 2026,
                    'period_month'       => 12,
                    'amount'             => '100.00',
                    'refrend_id'         => $competingRefrend->id,
                    'status'             => 'PENDING',
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }
        });

        try {
            $response = $this->actingAs($this->admin)->postJson($this->url($origin->id), [
                'months' => [['year' => 2026, 'month' => 12]],
            ]);

            $response->assertStatus(422);
            $response->assertJson(['res' => false]);
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.creating: ' . ScholarshipAdvancePaymentMonth::class);
        }
    }

    /** @test */
    public function a_non_admin_user_is_rejected_the_same_way_recordsituation_is(): void
    {
        $origin = $this->makeOriginRefrend();

        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $response = $this->actingAs($becario)->postJson($this->url($origin->id), [
            'months' => [['year' => 2026, 'month' => 12]],
        ]);

        $response->assertStatus(403);
    }
}
