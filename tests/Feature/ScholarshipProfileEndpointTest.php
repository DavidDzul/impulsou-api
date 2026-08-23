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
    public function rejects_a_second_temporary_increase_while_one_is_scheduled_for_the_future_without_replace_flag(): void
    {
        // Design D-4.3: "vigente" for this uniqueness check also covers an
        // increase that hasn't started yet (valid_from in the future) as long
        // as its valid_until hasn't passed — it WILL become active and must
        // not be silently overwritten. This is intentionally broader than
        // isTemporaryIncreaseActiveOn() (today-only).
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        ScholarshipProfile::factory()->create([
            'user_id'                          => $becario->id,
            'temporary_increase_amount'        => 500,
            'temporary_increase_valid_from'    => Carbon::today()->addMonth()->toDateString(),
            'temporary_increase_valid_until'   => Carbon::today()->addMonth()->addDays(10)->toDateString(),
            'temporary_increase_reason'        => 'Apoyo transporte futuro',
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

    // ── BUG FIX: sending ONLY temporary_increase_amount: null must clear ───
    // ── the whole block (no orphaned dates left behind) ─────────────────────

    /** @test */
    public function sending_only_temporary_increase_amount_null_clears_the_whole_block(): void
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

        // Only the amount is sent — the other 3 fields are NOT included in
        // the payload at all (this is the exact partial-update shape that
        // previously left temporary_increase_valid_until "orphaned" with a
        // future date while amount/from/reason were cleared).
        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            ['temporary_increase_amount' => null]
        );

        $response->assertStatus(200);

        $this->assertDatabaseHas('scholarship_profiles', [
            'user_id'                           => $becario->id,
            'temporary_increase_amount'         => null,
            'temporary_increase_valid_from'     => null,
            'temporary_increase_valid_until'    => null,
            'temporary_increase_reason'         => null,
            'temporary_increase_granted_by_id'  => null,
        ]);
    }

    /** @test */
    public function granting_a_new_temporary_increase_after_a_partial_clear_is_not_blocked_as_duplicate(): void
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

        // Step 1: partial clear (amount only).
        $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            ['temporary_increase_amount' => null]
        )->assertStatus(200);

        // Step 2: granting a brand-new increase must NOT be rejected as
        // "already exists a valid one" — the orphaned valid_until from step 1
        // must not leak into the vigencia check.
        $response = $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$becario->id}",
            [
                'temporary_increase_amount'      => 800,
                'temporary_increase_valid_from'  => Carbon::today()->toDateString(),
                'temporary_increase_valid_until' => Carbon::tomorrow()->addDays(20)->toDateString(),
                'temporary_increase_reason'      => 'Nuevo motivo',
            ]
        );

        $response->assertStatus(200);
        $this->assertEquals(800, (float) $response->json('data.temporary_increase_amount'));
    }

    // ── GET show() — expone el aumento temporal activo ─────────────────────

    /** @test */
    public function show_endpoint_includes_the_temporary_increase_fields_and_granted_by_relation(): void
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

        $response = $this->actingAs($this->admin)->getJson(
            "/api/admin/scholarship-profiles/{$becario->id}"
        );

        $response->assertStatus(200);
        $this->assertEquals(500, (float) $response->json('data.temporary_increase_amount'));
        $this->assertSame(
            Carbon::yesterday()->toDateString(),
            Carbon::parse($response->json('data.temporary_increase_valid_from'))->toDateString()
        );
        $this->assertSame(
            Carbon::tomorrow()->addDays(10)->toDateString(),
            Carbon::parse($response->json('data.temporary_increase_valid_until'))->toDateString()
        );
        $this->assertSame('Apoyo transporte', $response->json('data.temporary_increase_reason'));
        $this->assertSame($this->admin->id, $response->json('data.temporary_increase_granted_by_id'));
        $this->assertSame($this->admin->id, $response->json('data.granted_by.id'));
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
