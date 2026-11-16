<?php

namespace Tests\Unit;

use App\Support\PsPermissionCatalog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * administradores-psicol PR1: the PHP catalog must describe EVERY PS_* the seeder creates, so a
 * new PS_* permission without copy fails here instead of reaching the staff role editor as a
 * bare name.
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
    public function every_seeded_ps_permission_has_a_catalog_entry_with_spanish_copy_and_valid_kind(): void
    {
        $seeded = $this->seededPsNames();
        $this->assertNotEmpty($seeded);

        foreach ($seeded as $name) {
            $this->assertContains($name, PsPermissionCatalog::mappedNames(), "$name has no PsPermissionCatalog entry.");

            $meta = PsPermissionCatalog::metaFor($name);
            $this->assertNotSame('', trim($meta['module']), "$name has an empty module.");
            $this->assertNotSame(PsPermissionCatalog::UNKNOWN_MODULE, $meta['module'], "$name falls in the unknown module.");
            $this->assertNotSame('', trim($meta['description']), "$name has an empty description.");
            $this->assertNotSame($name, $meta['description'], "$name description is just its name.");
            $this->assertContains($meta['kind'], PsPermissionCatalog::KINDS, "$name has an invalid kind.");
        }
    }

    /** @test */
    public function every_catalog_entry_matches_a_seeded_permission(): void
    {
        $this->assertEqualsCanonicalizing($this->seededPsNames(), PsPermissionCatalog::mappedNames());
    }

    /** @test */
    public function group_permissions_are_menu_groups_and_pages_point_to_an_existing_menu_group(): void
    {
        foreach (PsPermissionCatalog::mappedNames() as $name) {
            $meta = PsPermissionCatalog::metaFor($name);

            if (str_starts_with($name, 'PS_GROUP_')) {
                $this->assertSame(PsPermissionCatalog::KIND_MENU_GROUP, $meta['kind'], $name);
                $this->assertNull($meta['menu_group'], $name);
            } else {
                $this->assertNotSame(PsPermissionCatalog::KIND_MENU_GROUP, $meta['kind'], $name);
            }

            if ($meta['kind'] === PsPermissionCatalog::KIND_PAGE) {
                $this->assertNotNull($meta['menu_group'], "$name is a page without menu_group.");
                $this->assertSame(
                    PsPermissionCatalog::KIND_MENU_GROUP,
                    PsPermissionCatalog::metaFor($meta['menu_group'])['kind'],
                    "$name menu_group is not a menu-group permission."
                );
            }
        }
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
    public function unknown_ps_permission_in_the_database_lands_in_otros_as_an_action_with_its_name(): void
    {
        Permission::create(['name' => 'PS_BRAND_NEW', 'type' => 'ADMIN', 'guard_name' => 'web']);

        $meta = PsPermissionCatalog::metaFor('PS_BRAND_NEW');
        $this->assertSame('Otros', $meta['module']);
        $this->assertSame('PS_BRAND_NEW', $meta['description']);
        $this->assertSame('action', $meta['kind']);

        $modules = PsPermissionCatalog::grouped();
        $last = end($modules);
        $this->assertSame('Otros', $last['module']);
        $this->assertSame(['PS_BRAND_NEW'], array_column($last['permissions'], 'name'));
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
    public function the_catalog_copy_overrides_the_database_copy_of_ps_view_all_campuses(): void
    {
        Permission::where('name', 'PS_VIEW_ALL_CAMPUSES')->update(['module' => 'Zzz', 'description' => 'Texto viejo']);

        $item = collect(PsPermissionCatalog::grouped())->pluck('permissions')->flatten(1)
            ->firstWhere('name', 'PS_VIEW_ALL_CAMPUSES');

        $this->assertNotSame('Texto viejo', $item['description']);
        $this->assertSame(PsPermissionCatalog::metaFor('PS_VIEW_ALL_CAMPUSES')['description'], $item['description']);
    }
}
