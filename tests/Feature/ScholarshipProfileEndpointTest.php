<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScholarshipProfileEndpointTest extends TestCase
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

    // ── advance_payment_eligible (beca-pago-adelantado-cert) ───────────────────

    /** @test */
    public function updating_a_profile_persists_advance_payment_eligible_true(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create([
            'user_id'                  => $becario->id,
            'advance_payment_eligible' => false,
        ]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            ['advance_payment_eligible' => true]
        );

        $response->assertStatus(200);
        $this->assertTrue((bool) $response->json('data.advance_payment_eligible'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                  => $becario->id,
            'advance_payment_eligible' => true,
        ]);
    }

    /** @test */
    public function updating_a_profile_persists_advance_payment_eligible_false(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create([
            'user_id'                  => $becario->id,
            'advance_payment_eligible' => true,
        ]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            ['advance_payment_eligible' => false]
        );

        $response->assertStatus(200);
        $this->assertFalse((bool) $response->json('data.advance_payment_eligible'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                  => $becario->id,
            'advance_payment_eligible' => false,
        ]);
    }

    // ── Aumento temporal — validación de rango ─────────────────────────────

    /** @test */
    public function rejects_temporary_increase_when_valid_until_is_not_after_valid_from(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            [
                'temporary_increase_amount'      => 500,
                'temporary_increase_valid_from'  => '2026-09-30',
                'temporary_increase_valid_until' => '2026-09-01',
                'temporary_increase_reason'      => 'Apoyo transporte',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('temporary_increase_valid_until');
    }

    /** @test */
    public function rejects_temporary_increase_amount_without_the_co_required_fields(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            ['temporary_increase_amount' => 500]
        );

        $response->assertStatus(422);
    }

    /** @test */
    public function rejects_discount_valid_from_missing_when_active_discount_percentage_is_set(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            [
                'active_discount_percentage' => 10,
                'discount_valid_until'       => Carbon::tomorrow()->toDateString(),
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('discount_valid_from');
    }

    // ── Aumento temporal — un solo aumento vigente a la vez ────────────────

    /** @test */
    public function rejects_a_second_temporary_increase_while_one_is_still_valid_without_replace_flag(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create([
            'user_id'                         => $becario->id,
            'temporary_increase_amount'       => 500,
            'temporary_increase_valid_from'   => Carbon::yesterday()->toDateString(),
            'temporary_increase_valid_until'  => Carbon::tomorrow()->addDays(10)->toDateString(),
            'temporary_increase_reason'       => 'Apoyo transporte',
            'temporary_increase_granted_by_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            [
                'temporary_increase_amount'      => 800,
                'temporary_increase_valid_from'  => Carbon::today()->toDateString(),
                'temporary_increase_valid_until' => Carbon::tomorrow()->addDays(20)->toDateString(),
                'temporary_increase_reason'      => 'Otro motivo',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('temporary_increase_amount');
    }

    /** @test */
    public function allows_replacing_a_valid_temporary_increase_with_explicit_flag(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create([
            'user_id'                          => $becario->id,
            'temporary_increase_amount'        => 500,
            'temporary_increase_valid_from'    => Carbon::yesterday()->toDateString(),
            'temporary_increase_valid_until'   => Carbon::tomorrow()->addDays(10)->toDateString(),
            'temporary_increase_reason'        => 'Apoyo transporte',
            'temporary_increase_granted_by_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            [
                'temporary_increase_amount'      => 800,
                'temporary_increase_valid_from'  => Carbon::today()->toDateString(),
                'temporary_increase_valid_until' => Carbon::tomorrow()->addDays(20)->toDateString(),
                'temporary_increase_reason'      => 'Otro motivo',
                'replace_temporary_increase'     => true,
            ]
        );

        $response->assertStatus(200);
        $this->assertEquals(800, (float) $response->json('data.temporary_increase_amount'));
        $this->assertSame('Otro motivo', $response->json('data.temporary_increase_reason'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                    => $becario->id,
            'temporary_increase_reason'  => 'Otro motivo',
        ]);
    }

    /** @test */
    public function resending_the_same_temporary_increase_values_is_a_no_op(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $from    = Carbon::yesterday()->toDateString();
        $until   = Carbon::tomorrow()->addDays(10)->toDateString();

        ScholarshipProfile::factory()->create([
            'user_id'                          => $becario->id,
            'temporary_increase_amount'        => 500,
            'temporary_increase_valid_from'    => $from,
            'temporary_increase_valid_until'   => $until,
            'temporary_increase_reason'        => 'Apoyo transporte',
            'temporary_increase_granted_by_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            [
                'temporary_increase_amount'      => 500,
                'temporary_increase_valid_from'  => $from,
                'temporary_increase_valid_until' => $until,
                'temporary_increase_reason'      => 'Apoyo transporte',
            ]
        );

        $response->assertStatus(200);
    }

    /** @test */
    public function clearing_the_temporary_increase_is_always_allowed_and_clears_granted_by(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create([
            'user_id'                          => $becario->id,
            'temporary_increase_amount'        => 500,
            'temporary_increase_valid_from'    => Carbon::yesterday()->toDateString(),
            'temporary_increase_valid_until'   => Carbon::tomorrow()->addDays(10)->toDateString(),
            'temporary_increase_reason'        => 'Apoyo transporte',
            'temporary_increase_granted_by_id' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            [
                'temporary_increase_amount'      => null,
                'temporary_increase_valid_from'  => null,
                'temporary_increase_valid_until' => null,
                'temporary_increase_reason'      => null,
            ]
        );

        $response->assertStatus(200);
        $this->assertNull($response->json('data.temporary_increase_amount'));
        $this->assertNull($response->json('data.temporary_increase_granted_by_id'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                           => $becario->id,
            'temporary_increase_amount'         => null,
            'temporary_increase_granted_by_id'  => null,
        ]);
    }

    /** @test */
    public function creating_a_new_temporary_increase_sets_granted_by_from_authenticated_user(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create(['user_id' => $becario->id]);

        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            [
                'temporary_increase_amount'      => 500,
                'temporary_increase_valid_from'  => Carbon::today()->toDateString(),
                'temporary_increase_valid_until' => Carbon::tomorrow()->addDays(10)->toDateString(),
                'temporary_increase_reason'      => 'Apoyo transporte',
            ]
        );

        $response->assertStatus(200);
        $this->assertSame($this->admin->id, $response->json('data.temporary_increase_granted_by_id'));

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                           => $becario->id,
            'temporary_increase_granted_by_id'  => $this->admin->id,
        ]);
    }

    // ── Store — mismas reglas de rango en discount_valid_from ──────────────

    /** @test */
    public function store_rejects_discount_valid_from_missing_when_active_discount_percentage_is_set(): void
    {
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $response = $this->actingAs($this->admin)->postJson(
            '/api/admin/scholarship-profiles',
            [
                'user_id'                    => $becario->id,
                'scholarship_type'           => 'IU',
                'monthly_amount'             => 2000,
                'active_discount_percentage' => 10,
                'discount_valid_until'       => Carbon::tomorrow()->toDateString(),
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('discount_valid_from');
    }

    // NOTE: an HTTP-level "store succeeds with a temporary increase" test was
    // considered here but is blocked by a PRE-EXISTING, out-of-scope issue:
    // `payment_start_date` is NOT NULL on the SQLite test schema (the legacy
    // column-drop migration is skipped for sqlite — see
    // ScholarshipProfileFactory) yet it has no validation rule in
    // StoreScholarshipProfileRequest, so ScholarshipProfile::create() with
    // $request->safe() data always violates that constraint under the test
    // suite regardless of this change. `granted_by_id`-on-create is instead
    // covered at the model/service level; the update() path (already tested
    // above) exercises the identical assignment logic.
}
