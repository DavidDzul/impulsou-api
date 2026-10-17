<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/limpieza-situaciones-en-desuso, api-1: the SUSPENDIDA / REEMBOLSO_PARCIAL
 * situations and the PENDIENTE_NOTIFICACION notify flow no longer exist.
 */
class DeadSituationsRemovedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
    }

    private function makeRefrend(): ScholarshipRefrend
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        return ScholarshipRefrend::create([
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
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);
    }

    /**
     * @test
     * @dataProvider deadResolutionPayloads
     */
    public function record_situation_rejects_dead_resolution_types_and_leaves_the_refrend_unchanged(array $payload): void
    {
        $refrend = $this->makeRefrend();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-refrends/{$refrend->id}/situation", $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['resolution_type']);

        $fresh = $refrend->fresh();
        $this->assertNull($fresh->resolution_type);
        $this->assertSame('DRAFT', $fresh->workflow_status);
        $this->assertSame('1000.00', (string) $fresh->final_amount);
    }

    public static function deadResolutionPayloads(): array
    {
        return [
            'SUSPENDIDA' => [['resolution_type' => 'SUSPENDIDA', 'resolution_cause' => 'x']],
            'REEMBOLSO_PARCIAL' => [['resolution_type' => 'REEMBOLSO_PARCIAL', 'refund_amount' => 300, 'resolution_notes' => 'x']],
        ];
    }

    /** @test */
    public function the_single_notify_route_no_longer_exists(): void
    {
        $refrend = $this->makeRefrend();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-refrends/{$refrend->id}/notify")
            ->assertNotFound();
    }

    /** @test */
    public function the_bulk_notify_route_no_longer_exists(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-refrends/bulk/notify', ['ids' => [1]])
            ->assertNotFound();
    }
}
