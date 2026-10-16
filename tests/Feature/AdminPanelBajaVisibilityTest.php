<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/egresado-baja-definitiva, amendment-5 (PR2c). administration-panel's
 * becario/user lists and profile (PR4, in a later batch) need `active` to
 * show a "Baja" chip, and the scholarship profile needs
 * `cycle_closed_period`/`cycle_closure_reason` to show the cycle-closed
 * chip. Both fields are already DB columns on models with no `$hidden`
 * entry for them and no explicit field whitelist in these controllers —
 * these tests PIN that the raw Eloquent serialization administration-panel
 * actually consumes (`api/admin/users`, `api/admin/users/{id}`,
 * `api/admin/graduates`, `api/admin/scholarship-profiles/{userId}`) already
 * exposes them, so no backend change was needed for this PR's slice of the
 * amendment. If any of these ever gain an explicit field whitelist, this
 * test turns RED and signals the new whitelist must include these fields.
 */
class AdminPanelBajaVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    /** @test */
    public function users_index_exposes_active_for_a_baja_becario(): void
    {
        User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => false]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/users');

        $response->assertStatus(200);
        $this->assertArrayHasKey('active', $response->json('users.0'));
        $this->assertFalse((bool) $response->json('users.0.active'));
    }

    /** @test */
    public function users_show_exposes_active_for_a_baja_becario(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => false]);

        $response = $this->actingAs($this->admin)->getJson("/api/admin/users/{$becario->id}");

        $response->assertStatus(200);
        $this->assertArrayHasKey('active', $response->json('user'));
        $this->assertFalse((bool) $response->json('user.active'));
    }

    /** @test */
    public function graduates_index_exposes_active_for_a_baja_egresado(): void
    {
        User::factory()->create(['user_type' => 'BEC_INACTIVE', 'active' => false]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/graduates');

        $response->assertStatus(200);
        $this->assertArrayHasKey('active', $response->json('graduates.0'));
        $this->assertFalse((bool) $response->json('graduates.0.active'));
    }

    /** @test */
    public function scholarship_profile_show_exposes_cycle_closure_fields(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => false]);
        ScholarshipProfile::factory()->create([
            'user_id'              => $becario->id,
            'cycle_closed_period'  => '2026-01-01',
            'cycle_closure_reason' => 'BAJA_DEFINITIVA',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/admin/scholarship-profiles/{$becario->id}");

        $response->assertStatus(200);
        // cycle_closed_period is a 'date'-cast column — like
        // TelmexCoverageController::serializeCoverage's start_period/
        // end_period, Eloquent's default toArray() emits a full ISO-8601
        // timestamp for it rather than "YYYY-MM-DD"; this is pre-existing,
        // unrelated-to-this-PR serialization behavior of the raw $profile
        // response, so the assertion only pins presence + the date part.
        $this->assertArrayHasKey('cycle_closed_period', $response->json('data'));
        $this->assertStringStartsWith('2026-01-01', $response->json('data.cycle_closed_period'));
        $this->assertSame('BAJA_DEFINITIVA', $response->json('data.cycle_closure_reason'));
    }
}
