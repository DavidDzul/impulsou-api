<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserBusinessVisibleScopeTest extends TestCase
{
    use RefreshDatabase;

    private const PERM = 'PS_VIEW_ALL_CAMPUSES';

    private User $bizMerida;
    private User $bizValladolid;
    private User $bizTizimin;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->bizMerida = $this->makeUser('BUSINESS', 'MERIDA');
        $this->bizValladolid = $this->makeUser('BUSINESS', 'VALLADOLID');
        $this->bizTizimin = $this->makeUser('BUSINESS', 'TIZIMIN');
        $this->adminUser = $this->makeUser('ADMIN', 'MERIDA');
    }

    private function makeUser(string $type, string $campus, ?string $role = null, bool $withPerm = false): User
    {
        $user = User::factory()->create(['user_type' => $type, 'campus' => $campus, 'active' => true]);
        if ($role) {
            $user->assignRole($role);
        }
        if ($withPerm) {
            $user->givePermissionTo(self::PERM);
        }

        return $user;
    }

    private function visibleIds(User $staff): array
    {
        return User::businessVisibleTo($staff)->pluck('id')->sort()->values()->all();
    }

    private function ids(User ...$users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    private function allBusinesses(): array
    {
        return $this->ids($this->bizMerida, $this->bizValladolid, $this->bizTizimin);
    }

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @test */
    public function root_sees_all_even_without_the_permission(): void
    {
        Role::findByName('ROOT')->revokePermissionTo(self::PERM);
        $this->forgetPermissionCache();

        $this->assertSame($this->allBusinesses(), $this->visibleIds($this->makeUser('ADMIN', 'MERIDA', 'ROOT')));
    }

    /** @test */
    public function root_job_in_valladolid_without_permission_sees_all(): void
    {
        $this->assertSame($this->allBusinesses(), $this->visibleIds($this->makeUser('ADMIN', 'VALLADOLID', 'ROOT_JOB')));
    }

    /** @test */
    public function merida_staff_with_permission_via_role_sees_all(): void
    {
        Role::findByName('ROOT_CAMPUS')->givePermissionTo(self::PERM);
        $this->forgetPermissionCache();

        $this->assertSame($this->allBusinesses(), $this->visibleIds($this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS')));
    }

    /** @test */
    public function merida_staff_with_direct_permission_sees_all(): void
    {
        $this->assertSame($this->allBusinesses(), $this->visibleIds($this->makeUser('ADMIN', 'MERIDA', 'ADMIN_JOB', true)));
    }

    /** @test */
    public function merida_staff_without_permission_sees_only_merida(): void
    {
        $this->assertSame($this->ids($this->bizMerida), $this->visibleIds($this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS')));
    }

    /** @test */
    public function non_merida_staff_with_permission_sees_only_own_campus(): void
    {
        $staff = $this->makeUser('ADMIN', 'VALLADOLID', 'ROOT_CAMPUS', true);

        $this->assertSame($this->ids($this->bizValladolid), $this->visibleIds($staff));
    }

    /** @test */
    public function merida_yucatan_with_permission_sees_all(): void
    {
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'YUCATAN', true);

        $this->assertSame($this->allBusinesses(), $this->visibleIds($staff));
    }

    /** @test */
    public function yucatan_without_permission_sees_own_campus_only(): void
    {
        $staff = $this->makeUser('ADMIN', 'MERIDA', 'YUCATAN');

        $this->assertSame($this->ids($this->bizMerida), $this->visibleIds($staff));
    }

    /** @test */
    public function root_administration_is_not_global_even_with_view_all_campuses(): void
    {
        $plain = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_ADMINISTRATION');
        $granted = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_ADMINISTRATION', true);

        $this->assertFalse($plain->hasGlobalBusinessVisibility());
        $this->assertSame($this->ids($this->bizMerida), $this->visibleIds($plain));
        $this->assertFalse($granted->hasGlobalBusinessVisibility());
        $this->assertSame($this->ids($this->bizMerida), $this->visibleIds($granted));
    }

    /** @test */
    public function non_global_staff_with_empty_or_null_campus_sees_nothing(): void
    {
        foreach (['', null] as $campus) {
            $staff = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');
            $staff->campus = $campus; // in memory: the campus column is an enum, '' cannot be stored

            $this->assertSame([], $this->visibleIds($staff));
        }
    }

    /** @test */
    public function missing_permission_row_degrades_gracefully(): void
    {
        $merida = $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS');
        $root = $this->makeUser('ADMIN', 'MERIDA', 'ROOT');
        Permission::where('name', self::PERM)->delete();
        $this->forgetPermissionCache();

        $this->assertSame($this->ids($this->bizMerida), $this->visibleIds($merida));
        $this->assertSame($this->allBusinesses(), $this->visibleIds($root));
        $this->assertFalse($merida->hasGlobalBusinessVisibility());
    }

    /** @test */
    public function non_business_users_are_never_returned(): void
    {
        $actors = [
            $this->makeUser('ADMIN', 'MERIDA', 'ROOT'),
            $this->makeUser('ADMIN', 'VALLADOLID', 'ROOT_JOB'),
            $this->makeUser('ADMIN', 'MERIDA', 'ADMIN_JOB', true),
            $this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS'),
        ];

        foreach ($actors as $actor) {
            $this->assertNotContains($this->adminUser->id, $this->visibleIds($actor));
            $this->assertTrue(User::businessVisibleTo($actor)->get()->every(fn ($u) => $u->user_type === 'BUSINESS'));
        }
    }

    /** @test */
    public function global_visibility_truth_table(): void
    {
        $this->assertTrue($this->makeUser('ADMIN', 'MERIDA', 'ROOT')->hasGlobalBusinessVisibility());
        $this->assertFalse($this->makeUser('ADMIN', 'MERIDA', 'ROOT_ADMINISTRATION')->hasGlobalBusinessVisibility());
        $this->assertTrue($this->makeUser('ADMIN', 'VALLADOLID', 'ROOT_JOB')->hasGlobalBusinessVisibility());
        $this->assertTrue($this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS', true)->hasGlobalBusinessVisibility());

        $this->assertFalse($this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS')->hasGlobalBusinessVisibility());
        $this->assertFalse($this->makeUser('ADMIN', 'VALLADOLID', 'ROOT_CAMPUS', true)->hasGlobalBusinessVisibility());
        $this->assertTrue($this->makeUser('ADMIN', 'MERIDA', 'YUCATAN', true)->hasGlobalBusinessVisibility());
        $this->assertFalse($this->makeUser('ADMIN', 'MERIDA', 'ADMIN_JOB')->hasGlobalBusinessVisibility());
    }

    /** @test */
    public function forced_campus_is_null_for_global_actors_and_own_campus_otherwise(): void
    {
        $this->assertNull(User::forcedBusinessCampusFor($this->makeUser('ADMIN', 'MERIDA', 'ROOT')));
        $this->assertSame('MERIDA', User::forcedBusinessCampusFor($this->makeUser('ADMIN', 'MERIDA', 'ROOT_ADMINISTRATION')));
        $this->assertNull(User::forcedBusinessCampusFor($this->makeUser('ADMIN', 'VALLADOLID', 'ROOT_JOB')));
        $this->assertNull(User::forcedBusinessCampusFor($this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS', true)));

        $this->assertSame('MERIDA', User::forcedBusinessCampusFor($this->makeUser('ADMIN', 'MERIDA', 'ROOT_CAMPUS')));
        $this->assertSame('VALLADOLID', User::forcedBusinessCampusFor($this->makeUser('ADMIN', 'VALLADOLID', 'ROOT_CAMPUS', true)));
        $this->assertNull(User::forcedBusinessCampusFor($this->makeUser('ADMIN', 'MERIDA', 'YUCATAN', true)));
        $this->assertSame('TIZIMIN', User::forcedBusinessCampusFor($this->makeUser('ADMIN', 'TIZIMIN', 'ADMIN_JOB')));
    }

    /** @test */
    public function seeder_creates_the_permission_for_root_only(): void
    {
        $permission = Permission::where('name', self::PERM)->first();

        $this->assertNotNull($permission);
        $this->assertSame('web', $permission->guard_name);
        $this->assertSame('ADMIN', $permission->type);
        $this->assertSame('Empresas', $permission->module);
        $this->assertNotEmpty($permission->description);

        $this->assertTrue(Role::findByName('ROOT')->hasPermissionTo(self::PERM));
        foreach (['ROOT_JOB', 'ROOT_CAMPUS', 'ADMIN_JOB', 'YUCATAN'] as $name) {
            $this->assertFalse(Role::findByName($name)->hasPermissionTo(self::PERM), $name);
        }
    }
}
