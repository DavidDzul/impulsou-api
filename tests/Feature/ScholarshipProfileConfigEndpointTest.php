<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the admin-only `PUT scholarship-profiles/{userId}/config` endpoint
 * (sdd/scholarship-profile-config-to-admin design D2/D9): the 4 config
 * fields (scholarship_type, monthly_amount, monto_apoyo,
 * advance_payment_eligible) are gated behind ADM_EDIT_SCHOLARSHIP_PROFILE
 * and no longer writable via the shared psicol-panel PUT/POST (see the
 * `prohibited` assertions in ScholarshipProfileEndpointTest.php).
 *
 * Also covers the D1 unblock: `POST scholarship-profiles` now succeeds with
 * only the 6 psicol-panel-owned fields, since `monthly_amount` defaults to
 * 0.00 and the guarded `payment_start_date` SQLite fix removes the previous
 * NOT-NULL blocker documented in ScholarshipProfileEndpointTest.php.
 */
class ScholarshipProfileConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create([
            'user_type' => 'ADMIN',
            'active'    => true,
        ]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');
    }

    private function becario(): User
    {
        return User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'active'    => true,
        ]);
    }

    // ── Permission gate ──────────────────────────────────────────────────

    /** @test */
    public function caller_without_permission_gets_403_and_no_field_changes(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $becario    = $this->becario();
        ScholarshipProfile::factory()->create([
            'user_id'        => $becario->id,
            'monthly_amount' => 1500,
        ]);

        $response = $this->actingAs($noPermUser)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'TELMEX',
                'monthly_amount'           => 3000,
                'monto_apoyo'              => 100,
                'advance_payment_eligible' => true,
            ]
        );

        $response->assertStatus(403);

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'        => $becario->id,
            'monthly_amount' => 1500,
        ]);
    }

    // ── Authorized writes ────────────────────────────────────────────────

    /** @test */
    public function authorized_admin_updates_all_4_fields_including_zero_amount(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'TELMEX',
                'monthly_amount'           => 0,
                'monto_apoyo'              => 250.50,
                'advance_payment_eligible' => true,
            ]
        );

        $response->assertStatus(200);
        $this->assertSame('TELMEX', $response->json('data.scholarship_type'));
        $this->assertEquals(0, (float) $response->json('data.monthly_amount'));
        $this->assertEquals(250.50, (float) $response->json('data.monto_apoyo'));
        $this->assertTrue((bool) $response->json('data.advance_payment_eligible'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                  => $becario->id,
            'scholarship_type'         => 'TELMEX',
            'monthly_amount'           => 0,
            'monto_apoyo'              => 250.50,
            'advance_payment_eligible' => true,
        ]);
    }

    /** @test */
    public function updating_config_persists_advance_payment_eligible_true(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create([
            'user_id'                  => $becario->id,
            'advance_payment_eligible' => false,
        ]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'IU',
                'monthly_amount'           => 2500,
                'monto_apoyo'              => 0,
                'advance_payment_eligible' => true,
            ]
        );

        $response->assertStatus(200);
        $this->assertTrue((bool) $response->json('data.advance_payment_eligible'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                  => $becario->id,
            'advance_payment_eligible' => true,
        ]);
    }

    /** @test */
    public function updating_config_persists_advance_payment_eligible_false(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create([
            'user_id'                  => $becario->id,
            'advance_payment_eligible' => true,
        ]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'IU',
                'monthly_amount'           => 2500,
                'monto_apoyo'              => 0,
                'advance_payment_eligible' => false,
            ]
        );

        $response->assertStatus(200);
        $this->assertFalse((bool) $response->json('data.advance_payment_eligible'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                  => $becario->id,
            'advance_payment_eligible' => false,
        ]);
    }

    // ── Validation ───────────────────────────────────────────────────────

    /** @test */
    public function monto_apoyo_is_required(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type' => 'IU',
                'monthly_amount'   => 2500,
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('monto_apoyo');
    }

    // ── iu_payment_amount conditional rule (sdd/scholarship-telmex-iu-split) ──

    /** @test */
    public function iu_payment_amount_is_required_when_type_is_telmex_iu(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'TELMEX_IU',
                'monthly_amount'           => 1800,
                'monto_apoyo'              => 150,
                'advance_payment_eligible' => false,
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('iu_payment_amount');
    }

    /** @test */
    public function iu_payment_amount_is_accepted_and_persisted_for_telmex_iu(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'TELMEX_IU',
                'monthly_amount'           => 1800,
                'monto_apoyo'              => 150,
                'iu_payment_amount'        => 300,
                'advance_payment_eligible' => false,
            ]
        );

        $response->assertStatus(200);
        $this->assertEquals(300, (float) $response->json('data.iu_payment_amount'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'           => $becario->id,
            'scholarship_type'  => 'TELMEX_IU',
            'iu_payment_amount' => 300,
        ]);
    }

    /** @test */
    public function iu_payment_amount_is_nulled_when_switching_a_profile_away_from_telmex_iu(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create([
            'user_id'           => $becario->id,
            'scholarship_type'  => 'TELMEX_IU',
            'iu_payment_amount' => 300,
        ]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'IU',
                'monthly_amount'           => 2500,
                'monto_apoyo'              => 0,
                'advance_payment_eligible' => false,
            ]
        );

        $response->assertStatus(200);
        $this->assertNull($response->json('data.iu_payment_amount'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'           => $becario->id,
            'scholarship_type'  => 'IU',
            'iu_payment_amount' => null,
        ]);
    }

    /** @test */
    public function iu_payment_amount_is_not_required_for_iu_or_telmex(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'TELMEX',
                'monthly_amount'           => 1800,
                'monto_apoyo'              => 150,
                'advance_payment_eligible' => false,
            ]
        );

        $response->assertStatus(200);
        $this->assertNull($response->json('data.iu_payment_amount'));
    }

    // ── Store endpoint — create without the 4 config fields (D1 unblock) ──

    /** @test */
    public function store_creates_a_profile_with_only_the_6_psicolpanel_owned_fields(): void
    {
        $becario = $this->becario();

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-profiles', [
            'user_id'                    => $becario->id,
            'active_discount_percentage' => 10,
            'discount_valid_from'        => now()->toDateString(),
            'discount_valid_until'       => now()->addMonth()->toDateString(),
            'discount_reason'            => 'Beca de excelencia',
        ]);

        $response->assertStatus(201);
        $this->assertEquals(0, (float) $response->json('data.monthly_amount'));
        $this->assertNull($response->json('data.monto_apoyo'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'        => $becario->id,
            'monthly_amount' => 0,
            'monto_apoyo'    => null,
        ]);
    }
}
