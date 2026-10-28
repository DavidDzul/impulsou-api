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
 * is_cert_unid) are gated behind ADM_EDIT_SCHOLARSHIP_PROFILE
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
                'is_cert_unid' => true,
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
                'is_cert_unid' => true,
            ]
        );

        $response->assertStatus(200);
        $this->assertSame('TELMEX', $response->json('data.scholarship_type'));
        $this->assertEquals(0, (float) $response->json('data.monthly_amount'));
        $this->assertEquals(250.50, (float) $response->json('data.monto_apoyo'));
        $this->assertTrue((bool) $response->json('data.is_cert_unid'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                  => $becario->id,
            'scholarship_type'         => 'TELMEX',
            'monthly_amount'           => 0,
            'monto_apoyo'              => 250.50,
            'is_cert_unid' => true,
        ]);
    }

    /** @test */
    public function updating_config_persists_is_cert_unid_true(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create([
            'user_id'                  => $becario->id,
            'is_cert_unid' => false,
        ]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'IU',
                'monthly_amount'           => 2500,
                'monto_apoyo'              => 0,
                'is_cert_unid' => true,
            ]
        );

        $response->assertStatus(200);
        $this->assertTrue((bool) $response->json('data.is_cert_unid'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                  => $becario->id,
            'is_cert_unid' => true,
        ]);
    }

    /** @test */
    public function updating_config_persists_is_cert_unid_false(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create([
            'user_id'                  => $becario->id,
            'is_cert_unid' => true,
        ]);

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            [
                'scholarship_type'         => 'IU',
                'monthly_amount'           => 2500,
                'monto_apoyo'              => 0,
                'is_cert_unid' => false,
            ]
        );

        $response->assertStatus(200);
        $this->assertFalse((bool) $response->json('data.is_cert_unid'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                  => $becario->id,
            'is_cert_unid' => false,
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
                'is_cert_unid' => false,
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
                'is_cert_unid' => false,
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
                'is_cert_unid' => false,
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
                'is_cert_unid' => false,
            ]
        );

        $response->assertStatus(200);
        $this->assertNull($response->json('data.iu_payment_amount'));
    }

    // ── Upsert + config_set_at marker (sdd/perfil-beca-montos-admin) ─────

    private function validConfig(array $override = []): array
    {
        return array_merge([
            'scholarship_type'         => 'TELMEX',
            'monthly_amount'           => 1800,
            'monto_apoyo'              => 150,
            'is_cert_unid' => true,
        ], $override);
    }

    /** @test */
    public function it_creates_the_profile_with_200_and_marker_when_missing(): void
    {
        $becario = $this->becario();

        $response = $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            $this->validConfig()
        );

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.config_set_at'));
        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                  => $becario->id,
            'scholarship_type'         => 'TELMEX',
            'monthly_amount'           => 1800,
            'monto_apoyo'              => 150,
            'is_cert_unid' => true,
        ]);
        $this->assertNotNull(ScholarshipProfile::where('user_id', $becario->id)->first()->config_set_at);
    }

    /** @test */
    public function it_refreshes_the_marker_on_every_save_and_leaves_other_fields_untouched(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create([
            'user_id'                    => $becario->id,
            'scholarship_type'           => 'TELMEX',
            'monthly_amount'             => 1800,
            'monto_apoyo'                => 150,
            'is_cert_unid'   => true,
            'active_discount_percentage' => 10,
            'discount_reason'            => 'Excelencia',
            'config_set_at'              => now()->subDays(5),
        ]);

        $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            $this->validConfig()
        )->assertStatus(200);

        $profile = ScholarshipProfile::where('user_id', $becario->id)->first();
        $this->assertTrue($profile->config_set_at->gt(now()->subMinute()));
        $this->assertEquals(10, (float) $profile->active_discount_percentage);
        $this->assertSame('Excelencia', $profile->discount_reason);
    }

    /** @test */
    public function an_unconfigured_existing_profile_becomes_configured(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->configPending()->create(['user_id' => $becario->id]);

        $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            $this->validConfig()
        )->assertStatus(200);

        $this->assertTrue(ScholarshipProfile::where('user_id', $becario->id)->first()->isConfigSet());
    }

    /** @test */
    public function zero_amounts_are_accepted_and_set_the_marker(): void
    {
        $becario = $this->becario();

        $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            $this->validConfig(['monthly_amount' => 0, 'monto_apoyo' => 0])
        )->assertStatus(200);

        $profile = ScholarshipProfile::where('user_id', $becario->id)->first();
        $this->assertEquals(0, (float) $profile->monthly_amount);
        $this->assertTrue($profile->isConfigSet());
    }

    /** @test */
    public function an_unknown_user_gets_404_and_no_orphan_profile(): void
    {
        $response = $this->actingAs($this->rootAdmin)->putJson(
            '/api/admin/scholarship-profiles/999999/config',
            $this->validConfig()
        );

        $response->assertStatus(404);
        $response->assertJson(['res' => false, 'msg' => 'Becario no encontrado.']);
        $this->assertSame(0, ScholarshipProfile::where('user_id', 999999)->count());
    }

    /** @test */
    public function an_unauthorized_caller_creates_nothing_when_profile_is_missing(): void
    {
        $noPermUser = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $becario    = $this->becario();

        $this->actingAs($noPermUser)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            $this->validConfig()
        )->assertStatus(403);

        $this->assertSame(0, ScholarshipProfile::where('user_id', $becario->id)->count());
    }

    /** @test */
    public function an_invalid_payload_creates_nothing_and_keeps_the_marker(): void
    {
        $becario = $this->becario();

        $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            ['scholarship_type' => 'IU']
        )->assertStatus(422);

        $this->assertSame(0, ScholarshipProfile::where('user_id', $becario->id)->count());
    }

    /** @test */
    public function the_marker_cannot_be_sent_through_the_config_payload(): void
    {
        $becario = $this->becario();

        $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            $this->validConfig(['config_set_at' => '2000-01-01 00:00:00'])
        )->assertStatus(200);

        $this->assertTrue(
            ScholarshipProfile::where('user_id', $becario->id)->first()->config_set_at->gt(now()->subMinute())
        );
    }

    /** @test */
    public function show_exposes_config_set_at(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->configPending()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->rootAdmin)->getJson("/api/admin/scholarship-profiles/{$becario->id}");

        $response->assertStatus(200);
        $this->assertTrue(array_key_exists('config_set_at', $response->json('data')));
        $this->assertNull($response->json('data.config_set_at'));
    }

    // ── Store/Update must never write the marker (R3) ───────────────────

    /** @test */
    public function store_leaves_the_marker_null(): void
    {
        $becario = $this->becario();

        $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-profiles', [
            'user_id' => $becario->id,
        ])->assertStatus(201);

        $this->assertNull(ScholarshipProfile::where('user_id', $becario->id)->first()->config_set_at);
    }

    /** @test */
    public function store_prohibits_config_set_at(): void
    {
        $becario = $this->becario();

        $response = $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-profiles', [
            'user_id'       => $becario->id,
            'config_set_at' => now()->toDateTimeString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('config_set_at');
    }

    /** @test */
    public function psicol_update_prohibits_config_set_at_and_never_clears_it(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            ['config_set_at' => null]
        )->assertStatus(422)->assertJsonValidationErrors('config_set_at');

        $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            ['discount_reason' => 'Otro motivo']
        )->assertStatus(200);

        $this->assertNotNull(ScholarshipProfile::where('user_id', $becario->id)->first()->config_set_at);
    }

    /** @test */
    public function psicol_store_after_admin_created_the_profile_is_rejected_as_duplicate(): void
    {
        $becario = $this->becario();

        $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            $this->validConfig()
        )->assertStatus(200);

        $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-profiles', [
            'user_id' => $becario->id,
        ])->assertStatus(422)->assertJsonValidationErrors('user_id');

        $this->assertSame(1, ScholarshipProfile::where('user_id', $becario->id)->count());
    }

    /** @test */
    public function saving_config_twice_for_a_user_without_profile_keeps_exactly_one_row(): void
    {
        $becario = $this->becario();
        $url     = "/api/admin/scholarship-profiles/{$becario->id}/config";

        $this->actingAs($this->rootAdmin)->putJson($url, $this->validConfig())->assertStatus(200);
        $this->actingAs($this->rootAdmin)->putJson($url, $this->validConfig(['monthly_amount' => 2222]))->assertStatus(200);

        $this->assertSame(1, ScholarshipProfile::where('user_id', $becario->id)->count());
        $this->assertEquals(2222, (float) ScholarshipProfile::where('user_id', $becario->id)->first()->monthly_amount);
    }

    /** @test */
    public function saving_config_for_a_psicol_created_profile_updates_the_same_single_row(): void
    {
        $becario = $this->becario();

        $this->actingAs($this->rootAdmin)->postJson('/api/admin/scholarship-profiles', [
            'user_id' => $becario->id,
        ])->assertStatus(201);
        $idBefore = ScholarshipProfile::where('user_id', $becario->id)->value('id');

        $this->actingAs($this->rootAdmin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}/config",
            $this->validConfig()
        )->assertStatus(200);

        $rows = ScholarshipProfile::where('user_id', $becario->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($idBefore, $rows->first()->id);
        $this->assertTrue($rows->first()->isConfigSet());
        $this->assertEquals(1800, (float) $rows->first()->monthly_amount);
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

    /** @test */
    public function the_legacy_advance_payment_eligible_key_is_ignored_and_not_returned(): void
    {
        $becario = $this->becario();
        ScholarshipProfile::factory()->create([
            'user_id'      => $becario->id,
            'is_cert_unid' => false,
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
        $this->assertArrayNotHasKey('advance_payment_eligible', $response->json('data'));
        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'      => $becario->id,
            'is_cert_unid' => false,
        ]);
    }
}
