<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validation coverage for POST /api/admin/scholarship-refrends/{id}/situation
 * scoped to DESCUENTO_DEFINITIVO. RETENIDA validation is already covered by
 * WithholdingPartialAmountTest — not duplicated here.
 */
class RecordSituationValidationTest extends TestCase
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

    private function makeRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'active'    => true,
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 1,
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
    public function it_returns_422_when_descuento_definitivo_is_missing_withholding_value(): void
    {
        $refrend = $this->makeRefrend();

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'  => 'DESCUENTO_DEFINITIVO',
                'withholding_mode' => 'percentage',
                'resolution_cause' => 'OTRO',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_value']);
    }

    /** @test */
    public function it_returns_422_when_descuento_definitivo_percentage_exceeds_100(): void
    {
        $refrend = $this->makeRefrend();

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'DESCUENTO_DEFINITIVO',
                'withholding_mode'   => 'percentage',
                'withholding_value'  => 150,
                'resolution_cause'   => 'OTRO',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_value']);
    }

    /** @test */
    public function it_returns_422_when_descuento_definitivo_fixed_amount_exceeds_due_amount(): void
    {
        $refrend = $this->makeRefrend(['base_amount' => 1000.00]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'DESCUENTO_DEFINITIVO',
                'withholding_mode'   => 'fixed',
                'withholding_value'  => 1500,
                'resolution_cause'   => 'OTRO',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_value']);
    }

    /** @test */
    public function it_accepts_a_valid_descuento_definitivo_request(): void
    {
        $refrend = $this->makeRefrend(['base_amount' => 1000.00]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'DESCUENTO_DEFINITIVO',
                'withholding_mode'   => 'percentage',
                'withholding_value'  => 25,
                'resolution_cause'   => 'BAJO_PROMEDIO',
            ]);

        $response->assertStatus(200);
        $this->assertSame('250.00', $response->json('data.discount_amount'));
        $this->assertSame('750.00', $response->json('data.final_amount'));
        $this->assertSame('DESCUENTO_DEFINITIVO', $response->json('data.resolution_type'));
    }
}
