<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET api/admin/permissions: additive can_view_all_campuses flag, existing keys unchanged.
 */
class PermissionsFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function as(string $role, string $campus, bool $withPermission = false): User
    {
        $actor = User::factory()->create(['user_type' => 'ADMIN', 'campus' => $campus, 'active' => true]);
        $actor->assignRole($role);
        if ($withPermission) {
            $actor->givePermissionTo('PS_VIEW_ALL_CAMPUSES');
        }
        Sanctum::actingAs($actor);

        return $actor;
    }

    public function test_permissions_key_is_unchanged(): void
    {
        $actor = $this->as('ROOT_CAMPUS', 'MERIDA');

        $response = $this->getJson('/api/admin/permissions')->assertOk();

        $this->assertEquals($actor->getAllPermissions()->pluck('name')->values()->all(), $response->json('permissions'));
        $this->assertEqualsCanonicalizing(['permissions', 'can_view_all_campuses'], array_keys($response->json()));
    }

    /**
     * @dataProvider flagTruthTable
     */
    public function test_can_view_all_campuses_truth_table(string $role, string $campus, bool $withPermission, bool $expected): void
    {
        $this->as($role, $campus, $withPermission);

        $this->getJson('/api/admin/permissions')->assertOk()->assertJsonPath('can_view_all_campuses', $expected);
    }

    public static function flagTruthTable(): array
    {
        return [
            'ROOT' => ['ROOT', 'MERIDA', false, true],
            'ROOT_JOB in Valladolid' => ['ROOT_JOB', 'VALLADOLID', false, true],
            'MERIDA ROOT_CAMPUS with permission' => ['ROOT_CAMPUS', 'MERIDA', true, true],
            'MERIDA ROOT_CAMPUS without permission' => ['ROOT_CAMPUS', 'MERIDA', false, false],
            'VALLADOLID holder' => ['ROOT_CAMPUS', 'VALLADOLID', true, false],
            'YUCATAN MERIDA holder' => ['YUCATAN', 'MERIDA', true, false],
            'ADMIN_JOB' => ['ADMIN_JOB', 'MERIDA', false, false],
        ];
    }

    public function test_flag_is_false_without_error_when_the_permission_row_is_deleted(): void
    {
        Permission::where('name', 'PS_VIEW_ALL_CAMPUSES')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->as('ROOT_CAMPUS', 'MERIDA');

        $this->getJson('/api/admin/permissions')->assertOk()->assertJsonPath('can_view_all_campuses', false);
    }
}
