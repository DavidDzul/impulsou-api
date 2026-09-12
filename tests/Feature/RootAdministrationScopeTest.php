<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers design (obs #1574) decision D1: User::isRoot() must treat ROOT_ADMINISTRATION as
 * root for campus-scoping purposes, so UserController@index / GraduateController@index
 * return an unscoped (cross-campus) list instead of silently filtering to `where('campus', ...)`
 * on a null/mismatched campus.
 */
class RootAdministrationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /** @test */
    public function root_administration_user_gets_unscoped_users_list(): void
    {
        $rootAdmin = User::factory()->create([
            'user_type' => 'ADMIN',
            'campus'    => 'MERIDA',
        ]);
        $rootAdmin->assignRole('ROOT_ADMINISTRATION');

        $meridaBecario = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
        ]);
        $tizininBecario = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'TIZIMIN',
        ]);

        $response = $this->actingAs($rootAdmin)->getJson('/api/admin/users');

        $response->assertStatus(200);
        $ids = collect($response->json('users'))->pluck('id')->toArray();

        $this->assertContains($meridaBecario->id, $ids);
        $this->assertContains($tizininBecario->id, $ids, 'ROOT_ADMINISTRATION must see becarios from other campuses too.');
    }

    /** @test */
    public function root_administration_user_gets_unscoped_graduates_list(): void
    {
        $rootAdmin = User::factory()->create([
            'user_type' => 'ADMIN',
            'campus'    => 'MERIDA',
        ]);
        $rootAdmin->assignRole('ROOT_ADMINISTRATION');

        $meridaGraduate = User::factory()->create([
            'user_type' => 'BEC_INACTIVE',
            'campus'    => 'MERIDA',
        ]);
        $tizininGraduate = User::factory()->create([
            'user_type' => 'BEC_INACTIVE',
            'campus'    => 'TIZIMIN',
        ]);

        $response = $this->actingAs($rootAdmin)->getJson('/api/admin/graduates');

        $response->assertStatus(200);
        $ids = collect($response->json('graduates'))->pluck('id')->toArray();

        $this->assertContains($meridaGraduate->id, $ids);
        $this->assertContains($tizininGraduate->id, $ids, 'ROOT_ADMINISTRATION must see graduates from other campuses too.');
    }

    /** @test */
    public function non_root_role_stays_campus_scoped_for_users(): void
    {
        $campusAdmin = User::factory()->create([
            'user_type' => 'ADMIN',
            'campus'    => 'MERIDA',
        ]);
        $campusAdmin->assignRole('ROOT_CAMPUS');

        $meridaBecario = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
        ]);
        $tizininBecario = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'TIZIMIN',
        ]);

        $response = $this->actingAs($campusAdmin)->getJson('/api/admin/users');

        $response->assertStatus(200);
        $ids = collect($response->json('users'))->pluck('id')->toArray();

        $this->assertContains($meridaBecario->id, $ids);
        $this->assertNotContains(
            $tizininBecario->id,
            $ids,
            'ROOT_CAMPUS must remain campus-scoped — the isRoot() fix must not broaden scoping for other roles.'
        );
    }

    /** @test */
    public function non_root_role_stays_campus_scoped_for_graduates(): void
    {
        $campusAdmin = User::factory()->create([
            'user_type' => 'ADMIN',
            'campus'    => 'MERIDA',
        ]);
        $campusAdmin->assignRole('ROOT_CAMPUS');

        $meridaGraduate = User::factory()->create([
            'user_type' => 'BEC_INACTIVE',
            'campus'    => 'MERIDA',
        ]);
        $tizininGraduate = User::factory()->create([
            'user_type' => 'BEC_INACTIVE',
            'campus'    => 'TIZIMIN',
        ]);

        $response = $this->actingAs($campusAdmin)->getJson('/api/admin/graduates');

        $response->assertStatus(200);
        $ids = collect($response->json('graduates'))->pluck('id')->toArray();

        $this->assertContains($meridaGraduate->id, $ids);
        $this->assertNotContains(
            $tizininGraduate->id,
            $ids,
            'ROOT_CAMPUS must remain campus-scoped — the isRoot() fix must not broaden scoping for other roles.'
        );
    }

    /** @test */
    public function root_role_is_unaffected_and_still_unscoped(): void
    {
        $root = User::factory()->create([
            'user_type' => 'ADMIN',
            'campus'    => 'MERIDA',
        ]);
        $root->assignRole('ROOT');

        $meridaBecario = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
        ]);
        $tizininBecario = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'TIZIMIN',
        ]);

        $response = $this->actingAs($root)->getJson('/api/admin/users');

        $response->assertStatus(200);
        $ids = collect($response->json('users'))->pluck('id')->toArray();

        $this->assertContains($meridaBecario->id, $ids);
        $this->assertContains($tizininBecario->id, $ids);
    }
}
