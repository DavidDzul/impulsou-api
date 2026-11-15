<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Users\UserDeactivationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Gate (403) -> scope resolution (404) -> validation (422) on the business resource.
 */
class BusinessAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $bizMerida;
    private User $bizValladolid;
    private User $bizTizimin;
    private User $adminTarget;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Mail::fake();

        $this->bizMerida = $this->make('BUSINESS', 'MERIDA', 'BASIC');
        $this->bizValladolid = $this->make('BUSINESS', 'VALLADOLID', 'BASIC');
        $this->bizTizimin = $this->make('BUSINESS', 'TIZIMIN', 'BASIC');
        $this->adminTarget = $this->make('ADMIN', 'VALLADOLID', 'ROOT_CAMPUS');
    }

    private function make(string $type, string $campus, ?string $role = null): User
    {
        $user = User::factory()->create(['user_type' => $type, 'campus' => $campus, 'active' => true]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function as(string $role, string $campus): User
    {
        $actor = $this->make('ADMIN', $campus, $role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'new.business@example.com',
            'phone' => '9991112233', 'campus' => 'TIZIMIN', 'password' => 'password123', 'role' => 'BASIC',
            'bs_name' => 'Acme', 'bs_director' => 'D', 'bs_rfc' => 'ABC010101AB1', 'bs_country' => 'Mexico',
            'bs_state' => 'Yucatan', 'bs_locality' => 'Merida', 'bs_adrress' => 'Calle 1',
            'bs_telphone' => '9991234567', 'bs_line' => 'Tech', 'bs_description' => 'desc',
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ], $overrides);
    }

    private function agreementPayload(): array
    {
        return ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'];
    }

    /** @test */
    public function root_reaches_every_verb(): void
    {
        $this->as('ROOT', 'MERIDA');

        $this->getJson('/api/admin/business')->assertOk();
        $this->getJson("/api/admin/business/{$this->bizValladolid->id}")->assertOk();
        $this->putJson("/api/admin/business/{$this->bizValladolid->id}", ['first_name' => 'X'])->assertOk();
        $this->postJson("/api/admin/business/{$this->bizValladolid->id}/agreement", $this->agreementPayload())->assertCreated();
        $this->postJson('/api/admin/business', $this->storePayload())->assertCreated();
    }

    /** @test */
    public function root_keeps_access_after_ps_permissions_are_revoked_from_its_role(): void
    {
        $role = Role::findByName('ROOT');
        foreach ($role->permissions->where('name', 'like', 'PS\_%') as $permission) {
            $role->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->as('ROOT', 'MERIDA');

        $this->getJson('/api/admin/business')->assertOk();
        $this->putJson("/api/admin/business/{$this->bizTizimin->id}", ['first_name' => 'X'])->assertOk();
        $this->postJson('/api/admin/business', $this->storePayload())->assertCreated();
    }

    /** @test */
    public function root_campus_cannot_reach_other_campus_business(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');
        $before = $this->bizTizimin->fresh()->first_name;

        $this->getJson("/api/admin/business/{$this->bizTizimin->id}")->assertNotFound();
        $this->putJson("/api/admin/business/{$this->bizTizimin->id}", ['first_name' => 'Hacked'])->assertNotFound();
        $this->postJson("/api/admin/business/{$this->bizTizimin->id}/agreement", $this->agreementPayload())->assertNotFound();

        $this->assertSame($before, $this->bizTizimin->fresh()->first_name);
        $this->assertDatabaseMissing('business_agreements', ['user_id' => $this->bizTizimin->id]);
        $this->getJson("/api/admin/business/{$this->bizValladolid->id}")->assertOk();
    }

    /** @test */
    public function out_of_scope_id_and_missing_id_return_the_same_not_found_body(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');

        $out = $this->getJson("/api/admin/business/{$this->bizTizimin->id}");
        $missing = $this->getJson('/api/admin/business/999999');

        $out->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->json('message'), $out->json('message'));
    }

    /** @test */
    public function out_of_scope_update_with_invalid_payload_is_404_not_422(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');

        $this->putJson("/api/admin/business/{$this->bizTizimin->id}", ['email' => 'not-an-email'])->assertNotFound();
        $this->postJson("/api/admin/business/{$this->bizTizimin->id}/agreement", [])->assertNotFound();
    }

    /** @test */
    public function out_of_scope_update_never_reaches_the_deactivation_service(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');
        $this->mock(UserDeactivationService::class)->shouldNotReceive('save');

        $this->putJson("/api/admin/business/{$this->bizTizimin->id}", ['active' => false])->assertNotFound();
    }

    /** @test */
    public function merida_staff_without_the_permission_is_limited_to_merida(): void
    {
        $this->as('ROOT_CAMPUS', 'MERIDA');

        $this->getJson("/api/admin/business/{$this->bizValladolid->id}")->assertNotFound();
        $this->getJson("/api/admin/business/{$this->bizMerida->id}")->assertOk();
    }

    /** @test */
    public function merida_staff_with_the_permission_reaches_other_campuses(): void
    {
        $actor = $this->as('ROOT_CAMPUS', 'MERIDA');
        $actor->givePermissionTo('PS_VIEW_ALL_CAMPUSES');

        $this->getJson("/api/admin/business/{$this->bizValladolid->id}")->assertOk();
        $this->putJson("/api/admin/business/{$this->bizValladolid->id}", ['first_name' => 'Ok'])->assertOk();
        $this->postJson("/api/admin/business/{$this->bizValladolid->id}/agreement", $this->agreementPayload())->assertCreated();
    }

    /** @test */
    public function non_merida_staff_holding_the_permission_is_still_limited_to_own_campus(): void
    {
        $actor = $this->as('ROOT_CAMPUS', 'VALLADOLID');
        $actor->givePermissionTo('PS_VIEW_ALL_CAMPUSES');

        $this->getJson("/api/admin/business/{$this->bizTizimin->id}")->assertNotFound();
    }

    /** @test */
    public function non_business_target_is_404_even_for_root(): void
    {
        $root = $this->as('ROOT', 'MERIDA');

        foreach ([$this->adminTarget, $root] as $target) {
            $this->getJson("/api/admin/business/{$target->id}")->assertNotFound();
            $this->putJson("/api/admin/business/{$target->id}", ['first_name' => 'X'])->assertNotFound();
            $this->postJson("/api/admin/business/{$target->id}/agreement", $this->agreementPayload())->assertNotFound();
        }
    }

    /** @test */
    public function yucatan_reads_own_campus_only_and_cannot_write(): void
    {
        $this->as('YUCATAN', 'MERIDA');

        $this->getJson("/api/admin/business/{$this->bizMerida->id}")->assertOk();
        $this->getJson("/api/admin/business/{$this->bizValladolid->id}")->assertNotFound();
        $this->putJson("/api/admin/business/{$this->bizMerida->id}", ['first_name' => 'X'])->assertForbidden();
        $this->postJson('/api/admin/business', $this->storePayload())->assertForbidden();
    }

    /** @test */
    public function non_global_staff_with_empty_or_null_campus_gets_404_by_id(): void
    {
        foreach (['', null] as $campus) {
            $actor = $this->as('ROOT_CAMPUS', 'MERIDA');
            $actor->campus = $campus; // in memory: the campus column is an enum, '' cannot be stored

            $this->getJson("/api/admin/business/{$this->bizMerida->id}")->assertNotFound();
        }
    }

    /** @test */
    public function non_numeric_ids_do_not_match_business_routes(): void
    {
        $this->as('ROOT', 'MERIDA');

        $this->getJson('/api/admin/business/1abc')->assertNotFound();
        $this->putJson('/api/admin/business/1abc', ['first_name' => 'X'])->assertNotFound();
        $this->postJson('/api/admin/business/1abc/agreement', $this->agreementPayload())->assertNotFound();
        $this->getJson('/api/admin/businessData/1abc')->assertNotFound();
        $this->getJson('/api/admin/businessAgreement/1abc')->assertNotFound();
    }

    /** @test */
    public function root_job_in_valladolid_sees_any_campus(): void
    {
        $this->as('ROOT_JOB', 'VALLADOLID');

        $this->getJson("/api/admin/business/{$this->bizMerida->id}")->assertOk();
        $this->getJson("/api/admin/business/{$this->bizTizimin->id}")->assertOk();
    }

    /** @test */
    public function root_campus_store_is_forced_to_own_campus(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');

        $this->postJson('/api/admin/business', $this->storePayload(['campus' => 'TIZIMIN']))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'new.business@example.com', 'campus' => 'VALLADOLID']);
    }

    /** @test */
    public function root_store_keeps_the_payload_campus(): void
    {
        $this->as('ROOT', 'MERIDA');

        $this->postJson('/api/admin/business', $this->storePayload(['campus' => 'TIZIMIN']))->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'new.business@example.com', 'campus' => 'TIZIMIN']);
    }

    /** @test */
    public function merida_store_campus_depends_on_the_permission(): void
    {
        $actor = $this->as('ROOT_CAMPUS', 'MERIDA');
        $this->postJson('/api/admin/business', $this->storePayload(['campus' => 'TIZIMIN']))->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'new.business@example.com', 'campus' => 'MERIDA']);

        $actor->givePermissionTo('PS_VIEW_ALL_CAMPUSES');
        $this->postJson('/api/admin/business', $this->storePayload(['campus' => 'TIZIMIN', 'email' => 'second@example.com']))->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'second@example.com', 'campus' => 'TIZIMIN']);
    }

    /** @test */
    public function update_cannot_move_a_business_out_of_the_staff_campus(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');

        $this->putJson("/api/admin/business/{$this->bizValladolid->id}", ['campus' => 'TIZIMIN'])->assertOk();

        $this->assertSame('VALLADOLID', $this->bizValladolid->fresh()->campus);
    }

    /** @test */
    public function merida_staff_with_the_permission_can_move_a_business_between_campuses(): void
    {
        $actor = $this->as('ROOT_CAMPUS', 'MERIDA');
        $actor->givePermissionTo('PS_VIEW_ALL_CAMPUSES');

        $this->putJson("/api/admin/business/{$this->bizValladolid->id}", ['campus' => 'OXKUTZCAB'])->assertOk();

        $this->assertSame('OXKUTZCAB', $this->bizValladolid->fresh()->campus);
    }

    /** @test */
    public function roles_without_business_permissions_get_403_on_every_endpoint(): void
    {
        Role::create(['name' => 'EMPTY_ROLE', 'guard_name' => 'web']);
        $this->as('EMPTY_ROLE', 'MERIDA');

        $this->assertForbiddenEverywhere();
    }

    /** @test */
    public function root_administration_gets_403_on_every_endpoint(): void
    {
        $this->as('ROOT_ADMINISTRATION', 'MERIDA');

        $this->assertForbiddenEverywhere();
    }

    private function assertForbiddenEverywhere(): void
    {
        $id = $this->bizMerida->id;

        $this->getJson('/api/admin/business')->assertForbidden();
        $this->getJson("/api/admin/business/{$id}")->assertForbidden();
        $this->postJson('/api/admin/business', $this->storePayload())->assertForbidden();
        $this->putJson("/api/admin/business/{$id}", ['first_name' => 'X'])->assertForbidden();
        $this->postJson("/api/admin/business/{$id}/agreement", $this->agreementPayload())->assertForbidden();
    }

    /** @test */
    public function success_payload_shapes_are_unchanged(): void
    {
        $this->as('ROOT', 'MERIDA');

        $this->getJson("/api/admin/business/{$this->bizMerida->id}")
            ->assertOk()->assertJsonPath('business.id', $this->bizMerida->id)->assertJsonPath('business.role.name', 'BASIC');
        $this->putJson("/api/admin/business/{$this->bizMerida->id}", ['first_name' => 'N'])
            ->assertOk()->assertJsonPath('res', true)->assertJsonPath('updateBusiness.first_name', 'N');
        $this->postJson('/api/admin/business', $this->storePayload())
            ->assertCreated()->assertJsonPath('res', true)->assertJsonStructure(['createBusiness' => ['id', 'role']]);
    }

    /** @test */
    public function unused_resource_routes_are_not_exposed(): void
    {
        $this->as('ROOT', 'MERIDA');

        $this->deleteJson("/api/admin/business/{$this->bizMerida->id}")->assertStatus(405);
    }
}
