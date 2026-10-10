<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/telmex-cobertura-iu, design D5: PATCH .../inline's final_amount_override
 * must never go below the covered Telmex part — that invariant protects
 * money IU already owes for the covered month from being silently erased
 * by a manual override.
 */
class ScholarshipRefrendInlineOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function makeCoveredRefrend(): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        $coverage = TelmexCoverage::create([
            'user_id'                        => $user->id,
            'scholarship_type_at_activation'  => ScholarshipType::TELMEX->value,
            'start_period'                    => '2026-09-01',
            'end_period'                      => null,
            'status'                          => 'ACTIVA',
        ]);

        return ScholarshipRefrend::create([
            'user_id'                        => $user->id,
            'period_year'                    => 2026,
            'period_month'                   => 9,
            'refrend_type'                   => RefrendType::NORMAL->value,
            'status'                         => RefrendStatus::DRAFT->value,
            'workflow_status'                => 'DRAFT',
            'base_amount'                    => 0.0,
            'snapshot_gross_amount'          => 0.0,
            'snapshot_telmex_covered_amount' => 1950.00,
            'snapshot_telmex_coverage_id'    => $coverage->id,
            'discount_percentage'            => 0,
            'discount_amount'                => 0,
            'final_amount'                   => 1950.00,
            'amount_pending_from_previous'   => 0,
            'snapshot_name'                  => 'Test Becario',
            'snapshot_generation'            => null,
            'snapshot_generation_id'         => null,
            'snapshot_campus'                => 'MERIDA',
            'snapshot_scholarship_type'      => ScholarshipType::TELMEX->value,
        ]);
    }

    private function url(int $refrendId): string
    {
        return "/api/admin/scholarship-refrends/{$refrendId}/inline";
    }

    /** @test */
    public function an_override_below_the_covered_amount_is_rejected(): void
    {
        $refrend = $this->makeCoveredRefrend();

        $response = $this->actingAs($this->admin)->patchJson($this->url($refrend->id), [
            'final_amount_override' => 1000.00,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['res' => false]);

        $refrend->refresh();
        $this->assertSame('1950.00', $refrend->final_amount);
    }

    /** @test */
    public function an_override_at_or_above_the_covered_amount_is_accepted(): void
    {
        $refrend = $this->makeCoveredRefrend();

        $response = $this->actingAs($this->admin)->patchJson($this->url($refrend->id), [
            'final_amount_override' => 1950.00,
        ]);

        $response->assertStatus(200);

        $refrend->refresh();
        $this->assertSame('1950.00', $refrend->final_amount);
    }

    /** @test */
    public function an_override_on_an_uncovered_refrend_is_unaffected_by_the_new_guard(): void
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        $refrend = ScholarshipRefrend::create([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 9,
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
        ]);

        $response = $this->actingAs($this->admin)->patchJson($this->url($refrend->id), [
            'final_amount_override' => 0.00,
        ]);

        $response->assertStatus(200);
        $refrend->refresh();
        $this->assertSame('0.00', $refrend->final_amount);
    }
}
