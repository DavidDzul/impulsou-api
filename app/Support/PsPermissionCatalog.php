<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;

/**
 * Catalog of the psicol-panel PS_* permissions grantable to staff roles.
 *
 * The copy (module, Spanish description) lives in the database (permissions.module /
 * permissions.description), like the ADM_* permissions: a new permission only needs a row with
 * type ADMIN, guard web, module and description. Missing copy falls back to module "Otros" and
 * description = name. The only data kept in code is the page -> menu group map (used by the
 * lockout warning of the panel).
 *
 * "kind" is derived from the name and tells the UI how a permission behaves in the sidebar:
 *  - menu-group (PS_GROUP_*): shows a whole menu section
 *  - action (PS_READ_* / PS_CREATE_* / PS_EDIT_* / PS_VIEW_*): a capability inside a page, no menu entry
 *  - page (anything else): shows one menu entry (child of `menu_group`)
 */
class PsPermissionCatalog
{
    public const KIND_MENU_GROUP = 'menu-group';
    public const KIND_PAGE = 'page';
    public const KIND_ACTION = 'action';

    public const KINDS = [self::KIND_MENU_GROUP, self::KIND_PAGE, self::KIND_ACTION];

    /** ROOT-only gate of the staff management module; never grantable to a staff role. */
    public const MANAGE_ADMINS = 'PS_MANAGE_ADMINS';

    public const UNKNOWN_MODULE = 'Otros';

    /** Display order of the known modules; unknown modules follow, "Otros" is always last. */
    private const MODULE_ORDER = ['Asistencias', 'Usuarios', 'Empresas', 'Vinculación laboral', 'Becas', 'Configuración'];

    /** Menu entry (page) -> menu group it hangs from. Unknown pages have no group. */
    private const PAGE_MENU_GROUP = [
        'PS_CHECK' => 'PS_GROUP_ATTENDANCE',
        'PS_CLASSES' => 'PS_GROUP_ATTENDANCE',
        'PS_USERS' => 'PS_GROUP_USERS',
        'PS_GRADUATES' => 'PS_GROUP_USERS',
        'PS_BUSINESS' => 'PS_GROUP_USERS',
        'PS_VACANT' => 'PS_GROUP_JOBS',
        'PS_APPLICATION' => 'PS_GROUP_JOBS',
        'PS_SCHOLARSHIPS_ATENCION' => 'PS_GROUP_SCHOLARSHIPS',
        'PS_SCHOLARSHIPS_PEDAGOGIA' => 'PS_GROUP_SCHOLARSHIPS',
        'PS_GENERATIONS' => 'PS_GROUP_CONFIG',
        'PS_ROLES' => 'PS_GROUP_JOBS',
        'PS_GRAPHICS' => 'PS_GROUP_JOBS',
        'PS_NOTICES' => 'PS_GROUP_ATTENDANCE',
        self::MANAGE_ADMINS => 'PS_GROUP_CONFIG',
    ];

    /**
     * Permissions a staff role may be granted: PS_* of type ADMIN / guard web, never the
     * management gate. Shared by the catalog endpoint and the sync validation.
     */
    public static function grantableQuery(): Builder
    {
        return Permission::query()
            ->where('type', 'ADMIN')
            ->where('guard_name', 'web')
            // "_" is a LIKE wildcard and SQLite has no default escape char: use an explicit
            // one ("!") that behaves the same on SQLite (tests) and MySQL (staging).
            ->whereRaw("name LIKE 'PS!_%' ESCAPE '!'")
            ->where('name', '!=', self::MANAGE_ADMINS);
    }

    /** @return string[] */
    public static function mappedNames(): array
    {
        return array_keys(self::PAGE_MENU_GROUP);
    }

    public static function kindFor(string $name): string
    {
        if (str_starts_with($name, 'PS_GROUP_')) {
            return self::KIND_MENU_GROUP;
        }

        foreach (['PS_READ_', 'PS_CREATE_', 'PS_EDIT_', 'PS_VIEW_'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return self::KIND_ACTION;
            }
        }

        return self::KIND_PAGE;
    }

    /**
     * @param  ?string  $module  permissions.module of the row
     * @param  ?string  $description  permissions.description of the row
     * @return array{module: string, description: string, kind: string, menu_group: ?string}
     */
    public static function metaFor(string $name, ?string $module = null, ?string $description = null): array
    {
        return [
            'module' => ($module === null || $module === '') ? self::UNKNOWN_MODULE : $module,
            'description' => ($description === null || $description === '') ? $name : $description,
            'kind' => self::kindFor($name),
            'menu_group' => self::PAGE_MENU_GROUP[$name] ?? null,
        ];
    }

    /**
     * Grantable permissions grouped by module: known modules in MODULE_ORDER, unknown ones after
     * (first-seen order), "Otros" last. Inside a module the menu-group comes first, then row (id) order.
     *
     * @return array<int, array{module: string, permissions: array<int, array<string, mixed>>}>
     */
    public static function grouped(): array
    {
        $permissions = self::grantableQuery()->orderBy('id')->get(['id', 'name', 'module', 'description']);

        $modules = [];
        foreach ($permissions as $permission) {
            $meta = self::metaFor($permission->name, $permission->module, $permission->description);
            $modules[$meta['module']][] = [
                'id' => $permission->id,
                'name' => $permission->name,
                'description' => $meta['description'],
                'kind' => $meta['kind'],
                'menu_group' => $meta['menu_group'],
            ];
        }

        $result = [];
        foreach ($modules as $module => $items) {
            $result[] = ['module' => (string) $module, 'permissions' => $items];
        }

        // Inside a module the menu-group permission goes first (usort is stable on PHP 8).
        foreach ($result as &$group) {
            usort($group['permissions'], fn ($a, $b) => ($b['kind'] === self::KIND_MENU_GROUP) <=> ($a['kind'] === self::KIND_MENU_GROUP));
        }
        unset($group);

        usort($result, fn ($a, $b) => self::moduleRank($a['module']) <=> self::moduleRank($b['module']));

        return $result;
    }

    private static function moduleRank(string $module): int
    {
        if ($module === self::UNKNOWN_MODULE) {
            return PHP_INT_MAX;
        }

        $index = array_search($module, self::MODULE_ORDER, true);

        return $index === false ? count(self::MODULE_ORDER) : $index;
    }
}
