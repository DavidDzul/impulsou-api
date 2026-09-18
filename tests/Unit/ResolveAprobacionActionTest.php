<?php

namespace Tests\Unit;

use App\Actions\Scholarship\ResolveAprobacionAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveAprobacionActionTest extends TestCase
{
    use RefreshDatabase;

    private ResolveAprobacionAction $action;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = $this->app->make(ResolveAprobacionAction::class);
        $this->admin  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($this->admin);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeConIncidenciaRefrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 5,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'CON_INCIDENCIA',
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

    // ── Setting a comment ────────────────────────────────────────────────────

    /** @test */
    public function it_sets_pedagogia_observations_when_a_comment_is_given(): void
    {
        $refrend = $this->makeConIncidenciaRefrend(['pedagogia_observations' => null]);

        $updated = $this->action->execute($refrend, ['comment' => 'Aprobado con condiciones.'], $this->admin->id);

        $this->assertSame('Aprobado con condiciones.', $updated->pedagogia_observations);
    }

    // ── Clearing a comment (bug: frontend's "remove" flow sends comment=null,
    // but the action silently ignored it, leaving the stale comment in place
    // while still reporting success) ────────────────────────────────────────

    /** @test */
    public function it_clears_an_existing_comment_when_comment_is_explicitly_null(): void
    {
        $refrend = $this->makeConIncidenciaRefrend(['pedagogia_observations' => 'Comentario previo.']);

        $updated = $this->action->execute($refrend, ['comment' => null], $this->admin->id);

        $this->assertNull($updated->pedagogia_observations);
    }

    /** @test */
    public function it_clears_an_existing_comment_when_comment_is_an_empty_string(): void
    {
        $refrend = $this->makeConIncidenciaRefrend(['pedagogia_observations' => 'Comentario previo.']);

        $updated = $this->action->execute($refrend, ['comment' => ''], $this->admin->id);

        $this->assertNull($updated->pedagogia_observations);
    }

    /** @test */
    public function it_leaves_the_existing_comment_untouched_when_the_comment_key_is_absent(): void
    {
        $refrend = $this->makeConIncidenciaRefrend(['pedagogia_observations' => 'Comentario previo.']);

        $updated = $this->action->execute($refrend, [], $this->admin->id);

        $this->assertSame('Comentario previo.', $updated->pedagogia_observations);
    }

    // ── Review stamp always applies ──────────────────────────────────────────

    /** @test */
    public function it_always_stamps_pedagogia_reviewed_by_and_at(): void
    {
        $refrend = $this->makeConIncidenciaRefrend();

        $updated = $this->action->execute($refrend, ['comment' => null], $this->admin->id);

        $this->assertSame($this->admin->id, $updated->pedagogia_reviewed_by_id);
        $this->assertNotNull($updated->pedagogia_reviewed_at);
    }

    // ── Guard ─────────────────────────────────────────────────────────────────

    /** @test */
    public function it_throws_when_workflow_status_is_not_con_incidencia(): void
    {
        $refrend = $this->makeConIncidenciaRefrend(['workflow_status' => 'DRAFT']);

        $this->expectException(\DomainException::class);

        $this->action->execute($refrend, ['comment' => 'x'], $this->admin->id);
    }
}
