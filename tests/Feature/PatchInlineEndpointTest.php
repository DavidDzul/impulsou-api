<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatchInlineEndpointTest extends TestCase
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'active'    => true,
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 5,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'base_amount'                  => 2500.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 2500.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function patchUrl(int $id): string
    {
        return "/api/admin/scholarship-refrends/{$id}/inline";
    }

    // ── TASK-11-2 Tests ───────────────────────────────────────────────────────

    /** @test */
    public function it_returns_422_when_refrend_is_locked_authorized(): void
    {
        $refrend = $this->makeRefrend([
            'status'          => RefrendStatus::AUTHORIZED->value,
            'workflow_status' => 'CLOSED',
            'locked_at'       => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson($this->patchUrl($refrend->id), [
                'atencion_observations' => 'Some observation',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['res' => false]);
        $this->assertStringContainsString('bloqueado', $response->json('msg'));
    }

    /** @test */
    public function it_returns_422_when_refrend_is_locked_paid(): void
    {
        $refrend = $this->makeRefrend([
            'status'          => RefrendStatus::PAID->value,
            'workflow_status' => 'CLOSED',
            'locked_at'       => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson($this->patchUrl($refrend->id), [
                'atencion_observations' => 'Some observation',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['res' => false]);
    }

    /** @test */
    public function it_returns_422_when_atencion_label_exceeds_60_chars(): void
    {
        $refrend = $this->makeRefrend();

        $longLabel = str_repeat('a', 61);

        $response = $this->actingAs($this->admin)
            ->patchJson($this->patchUrl($refrend->id), [
                'atencion_labels' => [$longLabel],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['atencion_labels.0']);
    }

    /** @test */
    public function it_returns_422_when_atencion_observations_exceeds_500_chars(): void
    {
        $refrend = $this->makeRefrend();

        $response = $this->actingAs($this->admin)
            ->patchJson($this->patchUrl($refrend->id), [
                'atencion_observations' => str_repeat('x', 501),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['atencion_observations']);
    }

    /** @test */
    public function it_returns_200_and_updates_fields_on_valid_patch(): void
    {
        $refrend = $this->makeRefrend();

        $labels       = ['Riesgo académico', 'Documentos pendientes'];
        $observations = 'Seguimiento necesario.';

        $response = $this->actingAs($this->admin)
            ->patchJson($this->patchUrl($refrend->id), [
                'atencion_labels'       => $labels,
                'atencion_observations' => $observations,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['res' => true]);

        $data = $response->json('data');

        $this->assertSame($labels, $data['atencion_labels']);
        $this->assertSame($observations, $data['atencion_observations']);
    }

    /** @test */
    public function it_writes_an_inline_update_log_after_valid_patch(): void
    {
        $refrend = $this->makeRefrend();

        $this->actingAs($this->admin)
            ->patchJson($this->patchUrl($refrend->id), [
                'atencion_observations' => 'Test observation.',
            ]);

        $logExists = ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
            ->where('action', 'inline_update')
            ->exists();

        $this->assertTrue($logExists, 'Expected ScholarshipRefrendLog with action=inline_update to exist.');
    }

    /** @test */
    public function it_does_not_change_status_after_valid_patch(): void
    {
        $refrend = $this->makeRefrend(['status' => RefrendStatus::ATENCION_REVIEW->value]);

        $this->actingAs($this->admin)
            ->patchJson($this->patchUrl($refrend->id), [
                'atencion_observations' => 'Some observation.',
            ]);

        $refrend->refresh();

        $this->assertSame(
            RefrendStatus::ATENCION_REVIEW,
            $refrend->status,
            'Status must not change after an inline patch.'
        );
    }

    /** @test */
    public function it_updates_pedagogia_observations_independently(): void
    {
        $refrend = $this->makeRefrend();

        $observations = 'Nota de pedagogía.';

        $response = $this->actingAs($this->admin)
            ->patchJson($this->patchUrl($refrend->id), [
                'pedagogia_observations' => $observations,
            ]);

        $response->assertStatus(200);
        $this->assertSame($observations, $response->json('data.pedagogia_observations'));
    }

    /** @test */
    public function it_returns_400_when_refrend_does_not_exist(): void
    {
        // Custom exception handler maps ModelNotFoundException → 400
        $response = $this->actingAs($this->admin)
            ->patchJson('/api/admin/scholarship-refrends/99999/inline', [
                'atencion_observations' => 'Test',
            ]);

        $response->assertStatus(400);
        $response->assertJson(['res' => false]);
    }
}
