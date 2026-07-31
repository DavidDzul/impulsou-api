<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithholdingPartialAmountTest extends TestCase
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

    // ── recordSituation validation ──────────────────────────────────────────

    /** @test */
    public function it_returns_422_when_withholding_value_exceeds_100_in_percentage_mode(): void
    {
        $refrend = $this->makeRefrend();

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'RETENIDA',
                'withholding_mode'   => 'percentage',
                'withholding_value'  => 150,
                'resolution_cause'   => 'BAJO_PROMEDIO',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_value']);
    }

    /** @test */
    public function it_returns_422_when_withholding_value_exceeds_base_amount_in_fixed_mode(): void
    {
        $refrend = $this->makeRefrend(['base_amount' => 1000.00]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'RETENIDA',
                'withholding_mode'   => 'fixed',
                'withholding_value'  => 1500,
                'resolution_cause'   => 'BAJO_PROMEDIO',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['withholding_value']);
    }

    /** @test */
    public function it_accepts_valid_partial_withholding_and_persists_amounts(): void
    {
        $refrend = $this->makeRefrend(['base_amount' => 1000.00]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->situationUrl($refrend->id), [
                'resolution_type'    => 'RETENIDA',
                'withholding_mode'   => 'percentage',
                'withholding_value'  => 30,
                'resolution_cause'   => 'BAJO_PROMEDIO',
            ]);

        $response->assertStatus(200);
        $this->assertSame('300.00', $response->json('data.discount_amount'));
        $this->assertSame('700.00', $response->json('data.final_amount'));
    }

    // ── bulkPay ──────────────────────────────────────────────────────────────

    /** @test */
    public function bulk_pay_pays_withheld_refrend_when_final_amount_is_positive(): void
    {
        $refrend = $this->makeRefrend([
            'workflow_status' => 'LISTO_PARA_PAGO',
            'status'          => RefrendStatus::WITHHELD->value,
            'discount_amount' => 300.00,
            'final_amount'    => 700.00,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-refrends/bulk/pay', ['ids' => [$refrend->id]]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.paid'));

        $refrend->refresh();
        $this->assertSame(RefrendStatus::PAID, $refrend->status);
        $this->assertSame('CLOSED', $refrend->workflow_status);
    }

    /** @test */
    public function bulk_pay_skips_withheld_refrend_when_final_amount_is_zero(): void
    {
        $refrend = $this->makeRefrend([
            'workflow_status' => 'LISTO_PARA_PAGO',
            'status'          => RefrendStatus::WITHHELD->value,
            'discount_amount' => 1000.00,
            'final_amount'    => 0.00,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-refrends/bulk/pay', ['ids' => [$refrend->id]]);

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.paid'));

        $refrend->refresh();
        $this->assertSame(RefrendStatus::WITHHELD, $refrend->status);
        $this->assertSame('LISTO_PARA_PAGO', $refrend->workflow_status);
    }
}
