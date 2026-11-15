<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating an administration role must never collide with a role of another
 * type or impersonate a reserved role: it is a 422, not a DB unique 500.
 */
class AdministrationRoleReservedNameTest extends TestCase
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

    /** @test */
    public function reserved_and_foreign_type_names_are_rejected_with_422(): void
    {
        $count = Role::count();

        // ROOT_CAMPUS is an existing ADMIN-type name; GOLD an existing USER-type tier.
        foreach (['ROOT', 'root', 'ROOT_JOB', 'ROOT_ADMINISTRATION', 'ROOT_CAMPUS', 'GOLD'] as $name) {
            $this->actingAs($this->rootAdmin)
                ->postJson('/api/admin/administration-roles', ['name' => $name])
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        $this->assertSame($count, Role::count());
    }

    /** @test */
    public function yucatan_and_business_tier_names_are_reserved_even_when_the_role_row_is_missing(): void
    {
        // Deleting the rows proves the RESERVED_NAMES check fires, not only the unique rule.
        Role::whereIn('name', ['YUCATAN', 'BASIC', 'DIAMOND'])->delete();
        $count = Role::count();

        foreach (['YUCATAN', 'yucatan', 'BASIC', 'BRONZE', 'SILVER', 'GOLD', 'PLATINUM', 'diamond'] as $name) {
            $this->actingAs($this->rootAdmin)
                ->postJson('/api/admin/administration-roles', ['name' => $name])
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        $this->assertSame($count, Role::count());
    }

    /** @test */
    public function a_fresh_name_is_still_created(): void
    {
        $this->actingAs($this->rootAdmin)
            ->postJson('/api/admin/administration-roles', ['name' => 'NUEVO_ROL_ADMIN'])
            ->assertStatus(201);

        $this->assertDatabaseHas('roles', ['name' => 'NUEVO_ROL_ADMIN', 'type' => 'ADMINISTRATION']);
    }
}
