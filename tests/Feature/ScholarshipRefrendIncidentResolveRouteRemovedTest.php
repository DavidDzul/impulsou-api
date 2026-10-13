<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers sdd/verificacion-incidencias-ux (PR4a, amendment #2) task 4a.2:
 * incidencias are never "resolved" in practice — Verificación creates them
 * and deletes them from the same modal, it never marks them resolved. The
 * PATCH {refrend}/incidents/{incident}/resolve route and
 * ScholarshipRefrendController::resolveIncident are dead code and must be
 * removed.
 */
class ScholarshipRefrendIncidentResolveRouteRemovedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function makeRefrendWithIncident(): array
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        $refrend = ScholarshipRefrend::create([
            'user_id'                 => $user->id,
            'period_year'             => 2026,
            'period_month'            => 9,
            'refrend_type'            => RefrendType::NORMAL->value,
            'status'                  => 'DRAFT',
            'workflow_status'         => 'CON_INCIDENCIA',
            'base_amount'             => 0.0,
            'discount_percentage'     => 0,
            'discount_amount'         => 0,
            'final_amount'            => 0.0,
            'amount_pending_from_previous' => 0,
            'snapshot_name'           => 'Test Becario',
            'snapshot_generation'     => null,
            'snapshot_generation_id'  => null,
            'snapshot_campus'         => 'MERIDA',
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ]);

        $incident = $refrend->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'INASISTENCIA',
            'incident_date'     => '2026-09-10',
            'description'       => 'Faltó a clase sin justificación.',
            'priority'          => 'MEDIUM',
            'created_by_id'     => null,
        ]);

        return [$refrend, $incident];
    }

    /** @test */
    public function patch_resolve_route_no_longer_exists(): void
    {
        [$refrend, $incident] = $this->makeRefrendWithIncident();

        $response = $this->actingAs($this->admin)->patchJson(
            "/api/admin/scholarship-refrends/{$refrend->id}/incidents/{$incident->id}/resolve",
            ['resolution_notes' => 'Resuelto manualmente para prueba.']
        );

        $response->assertStatus(404);
    }

    /** @test */
    public function resolve_incident_controller_method_does_not_exist(): void
    {
        $this->assertFalse(
            method_exists(\App\Http\Controllers\Admin\ScholarshipRefrendController::class, 'resolveIncident'),
            'ScholarshipRefrendController::resolveIncident must be removed along with its dead route.'
        );
    }
}
