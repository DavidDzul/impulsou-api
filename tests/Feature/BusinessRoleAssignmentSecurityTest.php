<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Privilege-escalation fix: BusinessController@store and @update used to pass
 * $request->role straight into assignRole()/syncRoles() with zero server-side
 * whitelist. Any authenticated user_type=ADMIN account could create/update a
 * business record while assigning itself ROOT or ROOT_ADMINISTRATION (this
 * project's two most privileged roles).
 *
 * The legitimate role set for a BUSINESS-type account is the membership-tier
 * roles seeded in RoleSeeder under "PANEL DE USUARIO" (BASIC, BRONZE, SILVER,
 * GOLD, PLATINUM, DIAMOND) — confirmed by database/factories/UserFactory.php +
 * database/seeders/UserSeeder.php, which create a user_type=BUSINESS account
 * and assignRole('DIAMOND'). Staff/admin roles (ROOT, ROOT_CAMPUS, YUCATAN,
 * ATTENDANCE, ADMIN_STUDENT, ROOT_JOB, ADMIN_JOB, ROOT_ADMINISTRATION) have no
 * business semantics and must never be assignable through this endpoint.
 */
class BusinessRoleAssignmentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Mail::fake();
    }

    private function actingAdmin(): User
    {
        $admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $admin->assignRole('ROOT');

        return $admin;
    }

    private function businessStorePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.business@example.com',
            'phone' => '9991112233',
            'campus' => 'MERIDA',
            'password' => 'password123',
            'bs_name' => 'Acme Corp',
            'bs_director' => 'John Director',
            'bs_rfc' => 'ABC010101AB1',
            'bs_country' => 'Mexico',
            'bs_state' => 'Yucatan',
            'bs_locality' => 'Merida',
            'bs_adrress' => 'Calle 1',
            'bs_telphone' => '9991234567',
            'bs_line' => 'Technology',
            'bs_description' => 'A business',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ], $overrides);
    }

    /** @test */
    public function store_rejects_root_role_assignment_with_422(): void
    {
        $admin = $this->actingAdmin();

        $response = $this->actingAs($admin)->postJson('/api/admin/business', $this->businessStorePayload([
            'role' => 'ROOT',
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'jane.business@example.com']);
    }

    /** @test */
    public function store_rejects_root_administration_role_assignment_with_422(): void
    {
        $admin = $this->actingAdmin();

        $response = $this->actingAs($admin)->postJson('/api/admin/business', $this->businessStorePayload([
            'role' => 'ROOT_ADMINISTRATION',
            'email' => 'jane.business2@example.com',
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'jane.business2@example.com']);
    }

    /** @test */
    public function store_allows_legitimate_business_tier_role(): void
    {
        $admin = $this->actingAdmin();

        $response = $this->actingAs($admin)->postJson('/api/admin/business', $this->businessStorePayload([
            'role' => 'DIAMOND',
            'email' => 'jane.business3@example.com',
        ]));

        $response->assertStatus(201);

        $created = User::where('email', 'jane.business3@example.com')->firstOrFail();
        $this->assertTrue($created->hasRole('DIAMOND'));
    }

    /** @test */
    public function update_rejects_root_administration_role_assignment_with_422(): void
    {
        $admin = $this->actingAdmin();

        $business = User::factory()->create([
            'user_type' => 'BUSINESS',
            'email' => 'existing.business@example.com',
        ]);
        $business->assignRole('BASIC');

        $response = $this->actingAs($admin)->putJson("/api/admin/business/{$business->id}", [
            'role' => 'ROOT_ADMINISTRATION',
        ]);

        $response->assertStatus(422);
        $this->assertTrue($business->fresh()->hasRole('BASIC'));
        $this->assertFalse($business->fresh()->hasRole('ROOT_ADMINISTRATION'));
    }

    /** @test */
    public function update_allows_legitimate_business_tier_role_change(): void
    {
        $admin = $this->actingAdmin();

        $business = User::factory()->create([
            'user_type' => 'BUSINESS',
            'email' => 'existing.business2@example.com',
        ]);
        $business->assignRole('BASIC');

        $response = $this->actingAs($admin)->putJson("/api/admin/business/{$business->id}", [
            'role' => 'GOLD',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($business->fresh()->hasRole('GOLD'));
    }
}
