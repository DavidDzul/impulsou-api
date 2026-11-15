<?php

namespace Tests\Feature;

use App\Models\BusinessAgreement;
use App\Models\BusinessData;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * businessData / businessAgreement / search: gate (403) -> scope resolution (404) -> validation (422).
 */
class BusinessDataAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $bizMerida;
    private User $bizValladolid;
    private User $adminTarget;
    private BusinessData $dataMerida;
    private BusinessData $dataValladolid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->bizMerida = $this->make('BUSINESS', 'MERIDA', 'BASIC');
        $this->bizValladolid = $this->make('BUSINESS', 'VALLADOLID', 'BASIC');
        $this->adminTarget = $this->make('ADMIN', 'VALLADOLID', 'ROOT_CAMPUS');

        $this->dataMerida = $this->businessData($this->bizMerida, 'Acme Merida');
        $this->dataValladolid = $this->businessData($this->bizValladolid, 'Acme Valladolid');
        $this->businessData($this->adminTarget, 'Acme Admin Row');
        $this->businessAgreement($this->bizMerida);
        $this->businessAgreement($this->bizValladolid);
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

    private function businessData(User $owner, string $name): BusinessData
    {
        return BusinessData::create([
            'user_id' => $owner->id, 'bs_name' => $name, 'bs_director' => 'D', 'bs_rfc' => 'ABC010101AB1',
            'bs_country' => 'Mexico', 'bs_state' => 'Yucatan', 'bs_locality' => 'Merida',
            'bs_adrress' => 'Calle 1', 'bs_telphone' => '9991234567', 'bs_line' => 'Tech', 'bs_description' => 'desc',
        ]);
    }

    private function businessAgreement(User $owner): BusinessAgreement
    {
        return BusinessAgreement::create(['user_id' => $owner->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    }

    private function searchNames(string $query = '?search=Acme'): array
    {
        return collect($this->getJson('/api/admin/search' . $query)->assertOk()->json('businesses'))
            ->pluck('bs_name')->sort()->values()->all();
    }

    /** @test */
    public function root_reads_and_updates_any_campus(): void
    {
        $this->as('ROOT', 'MERIDA');

        $this->getJson("/api/admin/businessData/{$this->bizValladolid->id}")
            ->assertOk()->assertJsonPath('data.bs_name', 'Acme Valladolid');
        $this->putJson("/api/admin/businessData/{$this->dataValladolid->id}", ['bs_name' => 'Renamed'])
            ->assertOk()->assertJsonPath('res', true)->assertJsonPath('updateData.bs_name', 'Renamed');
        $this->getJson("/api/admin/businessAgreement/{$this->bizValladolid->id}")
            ->assertOk()->assertJsonCount(1, 'agreements');
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

        $this->getJson("/api/admin/businessData/{$this->bizMerida->id}")->assertOk();
        $this->putJson("/api/admin/businessData/{$this->dataMerida->id}", ['bs_name' => 'X'])->assertOk();
        $this->getJson("/api/admin/businessAgreement/{$this->bizMerida->id}")->assertOk();
    }

    /** @test */
    public function in_scope_staff_reads_and_updates_own_campus(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');

        $this->getJson("/api/admin/businessData/{$this->bizValladolid->id}")->assertOk();
        $this->getJson("/api/admin/businessAgreement/{$this->bizValladolid->id}")->assertOk();
        $this->putJson("/api/admin/businessData/{$this->dataValladolid->id}", ['bs_name' => 'Mine'])->assertOk();
        $this->assertSame('Mine', $this->dataValladolid->fresh()->bs_name);
    }

    /** @test */
    public function out_of_scope_owner_is_404_and_update_leaves_the_database_unchanged(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');

        $this->getJson("/api/admin/businessData/{$this->bizMerida->id}")->assertNotFound();
        $this->getJson("/api/admin/businessAgreement/{$this->bizMerida->id}")->assertNotFound();
        $this->putJson("/api/admin/businessData/{$this->dataMerida->id}", ['bs_name' => 'Hacked'])->assertNotFound();

        $this->assertSame('Acme Merida', $this->dataMerida->fresh()->bs_name);
    }

    /** @test */
    public function out_of_scope_update_with_invalid_payload_is_404_not_422(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');

        $this->putJson("/api/admin/businessData/{$this->dataMerida->id}", ['bs_name' => str_repeat('x', 300)])->assertNotFound();
    }

    /** @test */
    public function out_of_scope_and_missing_ids_return_the_same_not_found_body(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');

        $out = $this->getJson("/api/admin/businessData/{$this->bizMerida->id}");
        $missing = $this->getJson('/api/admin/businessData/999999');
        $outPut = $this->putJson("/api/admin/businessData/{$this->dataMerida->id}", ['bs_name' => 'X']);
        $missingPut = $this->putJson('/api/admin/businessData/999999', ['bs_name' => 'X']);

        $out->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->json('message'), $out->json('message'));
        $this->assertSame($missingPut->json('message'), $outPut->json('message'));
        $this->getJson('/api/admin/businessAgreement/999999')->assertNotFound();
    }

    /** @test */
    public function non_business_owner_is_404_even_for_root(): void
    {
        $this->as('ROOT', 'MERIDA');
        $adminRow = BusinessData::where('user_id', $this->adminTarget->id)->first();

        $this->getJson("/api/admin/businessData/{$this->adminTarget->id}")->assertNotFound();
        $this->getJson("/api/admin/businessAgreement/{$this->adminTarget->id}")->assertNotFound();
        $this->putJson("/api/admin/businessData/{$adminRow->id}", ['bs_name' => 'X'])->assertNotFound();
        $this->assertSame('Acme Admin Row', $adminRow->fresh()->bs_name);
    }

    /** @test */
    public function merida_staff_without_the_permission_is_limited_to_merida(): void
    {
        $this->as('ROOT_CAMPUS', 'MERIDA');

        $this->getJson("/api/admin/businessData/{$this->bizMerida->id}")->assertOk();
        $this->getJson("/api/admin/businessData/{$this->bizValladolid->id}")->assertNotFound();
        $this->getJson("/api/admin/businessAgreement/{$this->bizValladolid->id}")->assertNotFound();
    }

    /** @test */
    public function merida_staff_with_the_permission_reaches_other_campuses(): void
    {
        $actor = $this->as('ROOT_CAMPUS', 'MERIDA');
        $actor->givePermissionTo('PS_VIEW_ALL_CAMPUSES');

        $this->getJson("/api/admin/businessData/{$this->bizValladolid->id}")->assertOk();
        $this->getJson("/api/admin/businessAgreement/{$this->bizValladolid->id}")->assertOk();
        $this->putJson("/api/admin/businessData/{$this->dataValladolid->id}", ['bs_name' => 'Ok'])->assertOk();
    }

    /** @test */
    public function yucatan_reads_own_campus_only_and_cannot_update(): void
    {
        $this->as('YUCATAN', 'MERIDA');

        $this->getJson("/api/admin/businessData/{$this->bizMerida->id}")->assertOk();
        $this->getJson("/api/admin/businessAgreement/{$this->bizMerida->id}")->assertOk();
        $this->getJson("/api/admin/businessData/{$this->bizValladolid->id}")->assertNotFound();
        $this->putJson("/api/admin/businessData/{$this->dataMerida->id}", ['bs_name' => 'X'])->assertForbidden();
    }

    /** @test */
    public function roles_without_business_permissions_get_403(): void
    {
        Role::create(['name' => 'EMPTY_ROLE', 'guard_name' => 'web']);
        $this->as('EMPTY_ROLE', 'MERIDA');

        $this->assertForbiddenEverywhere();
    }

    /** @test */
    public function root_administration_gets_403(): void
    {
        $this->as('ROOT_ADMINISTRATION', 'MERIDA');

        $this->assertForbiddenEverywhere();
    }

    private function assertForbiddenEverywhere(): void
    {
        $this->getJson("/api/admin/businessData/{$this->bizMerida->id}")->assertForbidden();
        $this->putJson("/api/admin/businessData/{$this->dataMerida->id}", ['bs_name' => 'X'])->assertForbidden();
        $this->getJson("/api/admin/businessAgreement/{$this->bizMerida->id}")->assertForbidden();
        $this->getJson('/api/admin/search?search=Acme')->assertForbidden();
    }

    /** @test */
    public function removed_routes_are_not_exposed(): void
    {
        $this->as('ROOT', 'MERIDA');
        $id = $this->bizMerida->id;

        foreach ([
            ['post', '/api/admin/businessData'],
            ['get', '/api/admin/businessData'],
            ['delete', "/api/admin/businessData/{$this->dataMerida->id}"],
            ['get', '/api/admin/businessAgreement'],
            ['post', '/api/admin/businessAgreement'],
            ['put', "/api/admin/businessAgreement/{$id}"],
            ['delete', "/api/admin/businessAgreement/{$id}"],
        ] as [$method, $url]) {
            $status = $this->{$method . 'Json'}($url)->getStatusCode();
            $this->assertContains($status, [400, 404, 405], "{$method} {$url} returned {$status}");
        }
    }

    /** @test */
    public function search_returns_only_in_scope_business_for_vacancy_only_permission(): void
    {
        Role::create(['name' => 'VACANT_ONLY', 'guard_name' => 'web'])->givePermissionTo('PS_CREATE_VACANT');
        $this->as('VACANT_ONLY', 'VALLADOLID');

        $response = $this->getJson('/api/admin/search?search=Acme')->assertOk();

        $this->assertSame(['Acme Valladolid'], collect($response->json('businesses'))->pluck('bs_name')->all());
        $this->assertSame(['id', 'bs_name', 'user_id'], array_keys($response->json('businesses.0')));
        $this->getJson('/api/admin/business')->assertForbidden();
    }

    /** @test */
    public function search_gate_accepts_each_listed_permission(): void
    {
        foreach (['PS_READ_BUSINESS', 'PS_CREATE_VACANT', 'PS_EDIT_VACANT'] as $permission) {
            $role = Role::create(['name' => "ONLY_{$permission}", 'guard_name' => 'web']);
            $role->givePermissionTo($permission);
            $this->as($role->name, 'VALLADOLID');

            $this->getJson('/api/admin/search?search=Acme')->assertOk();
        }
    }

    /** @test */
    public function search_is_limited_to_own_campus_for_merida_without_the_permission(): void
    {
        $this->as('ROOT_CAMPUS', 'MERIDA');

        $this->assertSame(['Acme Merida'], $this->searchNames());
    }

    /** @test */
    public function search_covers_all_campuses_for_merida_with_the_permission(): void
    {
        $actor = $this->as('ROOT_CAMPUS', 'MERIDA');
        $actor->givePermissionTo('PS_VIEW_ALL_CAMPUSES');

        $this->assertSame(['Acme Merida', 'Acme Valladolid'], $this->searchNames());
    }

    /** @test */
    public function search_is_limited_to_own_campus_for_a_non_merida_holder(): void
    {
        $actor = $this->as('ROOT_CAMPUS', 'VALLADOLID');
        $actor->givePermissionTo('PS_VIEW_ALL_CAMPUSES');

        $this->assertSame(['Acme Valladolid'], $this->searchNames());
    }

    /** @test */
    public function search_for_yucatan_returns_own_campus_only(): void
    {
        $this->as('YUCATAN', 'MERIDA');

        $this->assertSame(['Acme Merida'], $this->searchNames());
    }

    /** @test */
    public function search_for_root_returns_every_business_but_never_non_business_rows(): void
    {
        $this->as('ROOT', 'MERIDA');

        $this->assertSame(['Acme Merida', 'Acme Valladolid'], $this->searchNames());
    }

    /** @test */
    public function search_ignores_a_campus_query_parameter(): void
    {
        $this->as('ROOT_CAMPUS', 'VALLADOLID');

        $this->assertSame(['Acme Valladolid'], $this->searchNames('?search=Acme&campus=MERIDA'));
    }
}
