<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Exceptions\AdvanceDivergenceRequiredException;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-level coverage for the divergence-reason contract on
 * POST /api/admin/scholarship-refrends/{id}/situation (sdd/pago-adelantado,
 * design D4 — corrected 2026-09-25). Unit-level behavior of the divergence
 * check itself is covered by RecordPaymentSituationAdvanceReconciliationTest;
 * this file only proves the machine-readable `code` the frontend needs to
 * distinguish "please retry with a reason" from a plain rejection actually
 * reaches the HTTP response.
 */
class AdvanceDivergenceReasonEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function makeAdvancePaidRefrend(): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        $refrend = ScholarshipRefrend::create([
            'user_id'                      => $user->id,
            'period_year'                  => 2027,
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
        ]);

        ScholarshipAdvancePaymentMonth::factory()->create([
            'user_id'      => $refrend->user_id,
            'period_year'  => $refrend->period_year,
            'period_month' => $refrend->period_month,
            'amount'       => '1000.00',
            'refrend_id'   => $refrend->id,
            'status'       => 'PENDING',
        ]);

        return $refrend;
    }

    private function url(int $refrendId): string
    {
        return "/api/admin/scholarship-refrends/{$refrendId}/situation";
    }

    /** @test */
    public function it_returns_422_with_a_machine_readable_code_when_a_non_zero_resolution_is_missing_the_divergence_reason(): void
    {
        $refrend = $this->makeAdvancePaidRefrend();

        $response = $this->actingAs($this->admin)->postJson($this->url($refrend->id), [
            'resolution_type' => 'BECA_MES',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'res'  => false,
            'code' => AdvanceDivergenceRequiredException::CODE,
        ]);
    }

    /** @test */
    public function it_succeeds_once_the_divergence_reason_is_supplied_on_retry(): void
    {
        $refrend = $this->makeAdvancePaidRefrend();

        $response = $this->actingAs($this->admin)->postJson($this->url($refrend->id), [
            'resolution_type'           => 'BECA_MES',
            'advance_divergence_reason' => 'Autorizado por dirección.',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['res' => true]);
    }

    /** @test */
    public function sin_pago_never_needs_a_reason_and_never_returns_the_divergence_code(): void
    {
        $refrend = $this->makeAdvancePaidRefrend();

        $response = $this->actingAs($this->admin)->postJson($this->url($refrend->id), [
            'resolution_type' => 'SIN_PAGO',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['res' => true]);
    }
}
