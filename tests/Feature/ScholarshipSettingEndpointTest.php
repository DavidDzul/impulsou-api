<?php

namespace Tests\Feature;

use App\Models\ScholarshipSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers `GET/PUT admin/scholarship-settings` (sdd/scholarship-telmex-iu-split,
 * design D8): a single global, reference-only `telmex_base_amount`. GET has
 * no permission gate (shared reference data); PUT is gated behind
 * `ADM_MANAGE_SCHOLARSHIP_SETTINGS`.
 */
class ScholarshipSettingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');
    }

    // ── GET — no permission gate ─────────────────────────────────────────

    /** @test */
    public function any_authenticated_admin_can_read_the_setting(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $response = $this->actingAs($noPermUser)->getJson('/api/admin/scholarship-settings');

        $response->assertStatus(200);
        $this->assertSame('0.00', $response->json('data.telmex_base_amount'));
    }

    // ── PUT — permission gate ────────────────────────────────────────────

    /** @test */
    public function caller_without_permission_gets_403_and_value_is_unchanged(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $response = $this->actingAs($noPermUser)->putJson('/api/admin/scholarship-settings', [
            'telmex_base_amount' => 1500,
        ]);

        $response->assertStatus(403);
        $this->assertSame('0.00', ScholarshipSetting::current()->telmex_base_amount);
    }

    /** @test */
    public function authorized_admin_updates_the_value(): void
    {
        $response = $this->actingAs($this->rootAdmin)->putJson('/api/admin/scholarship-settings', [
            'telmex_base_amount' => 1500.50,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1500.50, (float) $response->json('data.telmex_base_amount'));

        $this->assertDatabaseHas('scholarship_settings', [
            'id'                 => 1,
            'telmex_base_amount' => 1500.50,
        ]);
    }

    /** @test */
    public function telmex_base_amount_is_required_and_numeric(): void
    {
        $response = $this->actingAs($this->rootAdmin)->putJson('/api/admin/scholarship-settings', [
            'telmex_base_amount' => 'not-a-number',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('telmex_base_amount');
    }
}
