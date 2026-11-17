<?php

namespace Tests\Unit;

use App\Support\PsPermissionCatalog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * administradores-psicol: the catalog reads module/description from the DB row (like ADM_*), derives
 * the kind from the name and keeps only the page -> menu group map in code. The seeder must give
 * EVERY PS_* its copy, so a permission without copy fails here instead of reaching the staff role
 * editor as a bare name.
 */
class PsPermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return string[] */
    private function seededPsNames(): array
    {
        return Permission::pluck('name')->filter(fn ($n) => str_starts_with($n, 'PS_'))->values()->all();
    }

    /** @test */
    public function every_seeded_ps_permission_has_db_copy_and_valid_kind(): void
    {
        $seeded = Permission::where('name', 'like', 'PS_%')->get();
        $this->assertNotEmpty($seeded);

        foreach ($seeded as $row) {
            $meta = PsPermissionCatalog::metaFor($row->name, $row->module, $row->description);

            $this->assertNotSame(PsPermissionCatalog::UNKNOWN_MODULE, $meta['module'], "{$row->name} has no module in the DB.");
            $this->assertSame($row->module, $meta['module']);
            $this->assertSame($row->description, $meta['description']);
            $this->assertNotSame($row->name, $meta['description'], "{$row->name} description is just its name.");
            $this->assertContains($meta['kind'], PsPermissionCatalog::KINDS, "{$row->name} has an invalid kind.");
        }
    }

    /** @test */
    public function every_mapped_page_is_seeded_and_points_to_a_seeded_menu_group(): void
    {
        $seeded = $this->seededPsNames();

        foreach (PsPermissionCatalog::mappedNames() as $name) {
            $this->assertContains($name, $seeded, "$name is mapped but not seeded.");
            $meta = PsPermissionCatalog::metaFor($name);
            $this->assertSame(PsPermissionCatalog::KIND_PAGE, $meta['kind'], "$name must be a page.");
            $this->assertContains($meta['menu_group'], $seeded);
            $this->assertSame(PsPermissionCatalog::KIND_MENU_GROUP, PsPermissionCatalog::kindFor($meta['menu_group']));
        }
    }

    /** @test */
    public function every_seeded_page_has_a_menu_group_and_other_kinds_have_none(): void
    {
        foreach ($this->seededPsNames() as $name) {
            $meta = PsPermissionCatalog::metaFor($name);

            if ($meta['kind'] === PsPermissionCatalog::KIND_PAGE) {
                $this->assertNotNull($meta['menu_group'], "$name is a page without menu_group.");
            } else {
                $this->assertNull($meta['menu_group'], "$name is not a page but has a menu_group.");
            }
        }
    }

    /** @test */
    public function roles_data_and_notices_live_under_their_domain_groups(): void
    {
        $this->assertSame('PS_GROUP_JOBS', PsPermissionCatalog::metaFor('PS_ROLES')['menu_group']);
        $this->assertSame('PS_GROUP_JOBS', PsPermissionCatalog::metaFor('PS_GRAPHICS')['menu_group']);
        $this->assertSame('PS_GROUP_ATTENDANCE', PsPermissionCatalog::metaFor('PS_NOTICES')['menu_group']);
        $this->assertSame('PS_GROUP_CONFIG', PsPermissionCatalog::metaFor('PS_GENERATIONS')['menu_group']);
        $this->assertSame('PS_GROUP_CONFIG', PsPermissionCatalog::metaFor('PS_MANAGE_ADMINS')['menu_group']);
    }

    /** @test */
    public function kind_is_derived_from_the_name(): void
    {
        $this->assertSame('menu-group', PsPermissionCatalog::kindFor('PS_GROUP_CONFIG'));
        $this->assertSame('action', PsPermissionCatalog::kindFor('PS_READ_USERS'));
        $this->assertSame('action', PsPermissionCatalog::kindFor('PS_CREATE_BUSINESS'));
        $this->assertSame('action', PsPermissionCatalog::kindFor('PS_EDIT_VACANT'));
        $this->assertSame('action', PsPermissionCatalog::kindFor('PS_VIEW_ALL_CAMPUSES'));
        $this->assertSame('page', PsPermissionCatalog::kindFor('PS_USERS'));
        $this->assertSame('page', PsPermissionCatalog::kindFor('PS_BRAND_NEW'));
    }

    /** @test */
    public function manage_admins_is_never_grantable(): void
    {
        $names = PsPermissionCatalog::grantableQuery()->pluck('name')->all();

        $this->assertNotContains('PS_MANAGE_ADMINS', $names);
        $this->assertContains('PS_USERS', $names);
    }

    /** @test */
    public function grantable_query_returns_only_admin_web_ps_permissions(): void
    {
        $rows = PsPermissionCatalog::grantableQuery()->get();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertStringStartsWith('PS_', $row->name);
            $this->assertSame('ADMIN', $row->type);
            $this->assertSame('web', $row->guard_name);
        }

        $names = $rows->pluck('name')->all();
        $this->assertSame([], preg_grep('/^(ADM_|CANDIDATES_|CREATE_VACANT_JR)/', $names));
    }

    /** @test */
    public function grantable_query_ignores_lookalike_names_the_like_wildcard_would_match(): void
    {
        // "_" is a LIKE wildcard: PSX... must NOT match "PS\_%".
        Permission::create(['name' => 'PSXHACK', 'type' => 'ADMIN', 'guard_name' => 'web']);
        Permission::create(['name' => 'PS_OTHER_GUARD', 'type' => 'ADMIN', 'guard_name' => 'sanctum']);

        $names = PsPermissionCatalog::grantableQuery()->pluck('name')->all();

        $this->assertNotContains('PSXHACK', $names);
        $this->assertNotContains('PS_OTHER_GUARD', $names);
    }

    /** @test */
    public function null_copy_in_the_database_falls_back_to_otros_and_the_name(): void
    {
        Permission::create(['name' => 'PS_BRAND_NEW', 'type' => 'ADMIN', 'guard_name' => 'web']);

        $meta = PsPermissionCatalog::metaFor('PS_BRAND_NEW');
        $this->assertSame('Otros', $meta['module']);
        $this->assertSame('PS_BRAND_NEW', $meta['description']);
        $this->assertSame('page', $meta['kind']);
        $this->assertNull($meta['menu_group']);

        $modules = PsPermissionCatalog::grouped();
        $last = end($modules);
        $this->assertSame('Otros', $last['module']);
        $this->assertSame(['PS_BRAND_NEW'], array_column($last['permissions'], 'name'));
        $this->assertSame('PS_BRAND_NEW', $last['permissions'][0]['description']);
    }

    /** @test */
    public function a_new_ps_permission_inserted_only_in_the_database_appears_with_its_copy_and_derived_kind(): void
    {
        Permission::create([
            'name' => 'PS_READ_REPORTS',
            'type' => 'ADMIN',
            'guard_name' => 'web',
            'module' => 'Reportes',
            'description' => 'Ver los reportes',
        ]);

        $group = collect(PsPermissionCatalog::grouped())->firstWhere('module', 'Reportes');

        $this->assertNotNull($group, 'The new module must show up without code changes.');
        $this->assertCount(1, $group['permissions']);
        $item = $group['permissions'][0];
        $this->assertSame('PS_READ_REPORTS', $item['name']);
        $this->assertSame('Ver los reportes', $item['description']);
        $this->assertSame('action', $item['kind']);
        $this->assertNull($item['menu_group']);
    }

    /** @test */
    public function modules_keep_a_stable_order_known_first_unknown_after_and_otros_last(): void
    {
        Permission::create(['name' => 'PS_ZZZ', 'type' => 'ADMIN', 'guard_name' => 'web', 'module' => 'Reportes', 'description' => 'Zzz']);
        Permission::create(['name' => 'PS_NOCOPY', 'type' => 'ADMIN', 'guard_name' => 'web']);

        $order = array_column(PsPermissionCatalog::grouped(), 'module');

        $this->assertSame(
            ['Asistencias', 'Usuarios', 'Empresas', 'Vinculación laboral', 'Becas', 'Configuración', 'Reportes', 'Otros'],
            $order
        );
    }

    /** @test */
    public function grouped_lists_grantable_permissions_by_module_without_the_manage_gate(): void
    {
        $modules = PsPermissionCatalog::grouped();
        $flat = collect($modules)->pluck('permissions')->flatten(1);

        $this->assertEqualsCanonicalizing(
            PsPermissionCatalog::grantableQuery()->pluck('name')->all(),
            $flat->pluck('name')->all()
        );
        $this->assertNotContains('PS_MANAGE_ADMINS', $flat->pluck('name')->all());
        $this->assertNotContains('Otros', array_column($modules, 'module'));

        $first = $flat->first();
        $this->assertSame(['id', 'name', 'description', 'kind', 'menu_group'], array_keys($first));
    }

    /** @test */
    public function the_database_copy_is_what_the_catalog_returns(): void
    {
        Permission::where('name', 'PS_VIEW_ALL_CAMPUSES')->update(['module' => 'Zzz', 'description' => 'Texto nuevo']);

        $groups = collect(PsPermissionCatalog::grouped());
        $item = $groups->pluck('permissions')->flatten(1)->firstWhere('name', 'PS_VIEW_ALL_CAMPUSES');

        $this->assertSame('Texto nuevo', $item['description']);
        $this->assertContains('Zzz', $groups->pluck('module')->all());
    }
}
