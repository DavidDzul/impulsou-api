<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipWithholding;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresado-baja-definitiva, PR2b, task 2b.9: the HTTP contract for the
 * four cycle-cut guards (CYCLE_CUT_BLOCKED, CYCLE_CUT_ACTIVE_TELMEX_COVERAGE,
 * CYCLE_CUT_PENDING_WITHHOLDINGS, CYCLE_ALREADY_CLOSED) via
 * POST /api/admin/scholarship-refrends/{id}/situation, plus the
 * `withholding_payments` prohibited_if validation rule for cut types.
 */
class CycleCutGuardsFeatureTest extends TestCase
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
    }

    private function makeBecario(): User
    {
        return User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);
    }

    private function makeRefrend(int $userId, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => 6,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
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

    private function situationUrl(int $id): string
    {
        return "/api/admin/scholarship-refrends/{$id}/situation";
    }

    /** @test */
    public function it_returns_422_prohibited_when_withholding_payments_are_sent_with_a_cut_resolution(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'       => 'EGRESADO',
                'withholding_payments'  => [['withholding_id' => 1, 'amount' => 100]],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_payments']);
    }

    /** @test */
    public function it_returns_cycle_cut_blocked_with_the_blocking_months_list(): void
    {
        $user         = $this->makeBecario();
        $refrend      = $this->makeRefrend($user->id, ['period_month' => 6]);
        $laterRefrend = $this->makeRefrend($user->id, [
            'period_month'    => 7,
            'workflow_status' => 'PAID',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type' => 'EGRESADO',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'res'  => false,
            'code' => 'CYCLE_CUT_BLOCKED',
            'data' => [
                'blocking_months' => [
                    ['year' => 2026, 'month' => 7, 'reason' => 'PAID'],
                ],
            ],
        ]);
        $this->assertSame('PAID', $laterRefrend->fresh()->workflow_status);
    }

    /** @test */
    public function it_returns_cycle_cut_active_telmex_coverage_with_coverage_data(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id);
        $coverage = TelmexCoverage::create([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => 'TELMEX',
            'start_period'                    => '2026-01-01',
            'status'                          => 'ACTIVA',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type' => 'BAJA_DEFINITIVA',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'res'  => false,
            'code' => 'CYCLE_CUT_ACTIVE_TELMEX_COVERAGE',
            'data' => [
                'coverage_id'  => $coverage->id,
                'status'       => 'ACTIVA',
                'start_period' => '2026-01-01',
            ],
        ]);
    }

    /** @test */
    public function it_returns_cycle_cut_pending_withholdings_with_the_pending_list(): void
    {
        $user          = $this->makeBecario();
        $refrend       = $this->makeRefrend($user->id, ['period_month' => 6]);
        $originRefrend = $this->makeRefrend($user->id, ['period_month' => 1]);
        $withholding   = ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $originRefrend->id,
            'period_year'       => 2026,
            'period_month'      => 1,
            'withheld_amount'   => '200.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type' => 'EGRESADO',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'res'  => false,
            'code' => 'CYCLE_CUT_PENDING_WITHHOLDINGS',
        ]);
        $this->assertSame($withholding->id, $response->json('data.withholdings.0.id'));
        $this->assertSame('200.00', $response->json('data.withholdings.0.remaining_amount'));
        $this->assertFalse($response->json('data.withholdings.0.is_current_refrend'));
    }

    /** @test */
    public function it_flags_the_cut_months_own_retention_in_the_422_rows(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id, ['period_month' => 6]);
        ScholarshipWithholding::create([
            'user_id'           => $user->id,
            'origin_refrend_id' => $refrend->id,
            'period_year'       => 2026,
            'period_month'      => 6,
            'withheld_amount'   => '200.00',
            'paid_amount'       => '0.00',
            'status'            => 'PENDING',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), ['resolution_type' => 'BAJA_DEFINITIVA']);

        $response->assertStatus(422);
        $response->assertJson(['code' => 'CYCLE_CUT_PENDING_WITHHOLDINGS']);
        $this->assertTrue($response->json('data.withholdings.0.is_current_refrend'));
    }

    /** @test */
    public function it_returns_cycle_already_closed_on_a_second_cut(): void
    {
        $user = $this->makeBecario();
        ScholarshipProfile::factory()->create([
            'user_id'             => $user->id,
            'cycle_closed_period' => '2026-05-01',
            'cycle_closure_reason' => 'EGRESADO',
        ]);
        $refrend = $this->makeRefrend($user->id, ['period_month' => 6]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type' => 'BAJA_DEFINITIVA',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'res'  => false,
            'code' => 'CYCLE_ALREADY_CLOSED',
        ]);
    }
}
