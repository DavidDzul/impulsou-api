<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\User;
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
}
