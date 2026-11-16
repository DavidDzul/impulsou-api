<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;

/**
 * Single source of truth for the copy (module, Spanish description, kind) of the psicol-panel
 * PS_* permissions. It is NOT persisted: RoleSeederAdministrationTest pins PS_* rows to NULL
 * module/description, and a PHP map cannot drift from the seeder (a unit test pins coverage).
 *
 * "kind" tells the UI how a permission behaves in the sidebar:
 *  - menu-group: shows a whole menu section
 *  - page: shows one menu entry (child of `menu_group`)
 *  - action: a capability inside a page, no menu entry
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

    /** Order of this map is the display order of modules and of permissions inside them. */
    private const MAP = [
        'PS_GROUP_ATTENDANCE' => ['Asistencias', 'Mostrar el menú Asistencias', self::KIND_MENU_GROUP, null],
        'PS_CHECK' => ['Asistencias', 'Entrar al checador', self::KIND_PAGE, 'PS_GROUP_ATTENDANCE'],
        'PS_CLASSES' => ['Asistencias', 'Entrar a las sesiones de formación', self::KIND_PAGE, 'PS_GROUP_ATTENDANCE'],

        'PS_GROUP_USERS' => ['Usuarios', 'Mostrar el menú Usuarios', self::KIND_MENU_GROUP, null],
        'PS_USERS' => ['Usuarios', 'Entrar a becarios y egresados', self::KIND_PAGE, 'PS_GROUP_USERS'],
        'PS_READ_USERS' => ['Usuarios', 'Ver la lista de becarios', self::KIND_ACTION, null],
        'PS_CREATE_USERS' => ['Usuarios', 'Registrar becarios', self::KIND_ACTION, null],
        'PS_EDIT_USERS' => ['Usuarios', 'Editar becarios', self::KIND_ACTION, null],
        'PS_GRADUATES' => ['Usuarios', 'Entrar a egresados', self::KIND_PAGE, 'PS_GROUP_USERS'],
        'PS_READ_GRADUATES' => ['Usuarios', 'Ver la lista de egresados', self::KIND_ACTION, null],
        'PS_CREATE_GRADUATES' => ['Usuarios', 'Registrar egresados', self::KIND_ACTION, null],
        'PS_EDIT_GRADUATES' => ['Usuarios', 'Editar egresados', self::KIND_ACTION, null],

        'PS_BUSINESS' => ['Empresas', 'Entrar a empresas', self::KIND_PAGE, 'PS_GROUP_USERS'],
        'PS_READ_BUSINESS' => ['Empresas', 'Ver la lista de empresas', self::KIND_ACTION, null],
        'PS_CREATE_BUSINESS' => ['Empresas', 'Registrar empresas', self::KIND_ACTION, null],
        'PS_EDIT_BUSINESS' => ['Empresas', 'Editar empresas', self::KIND_ACTION, null],
        'PS_VIEW_ALL_CAMPUSES' => ['Empresas', 'Ver empresas de todas las sedes (solo personal de Mérida)', self::KIND_ACTION, null],

        'PS_GROUP_JOBS' => ['Vinculación laboral', 'Mostrar el menú Vinculación laboral', self::KIND_MENU_GROUP, null],
        'PS_VACANT' => ['Vinculación laboral', 'Entrar a vacantes', self::KIND_PAGE, 'PS_GROUP_JOBS'],
        'PS_READ_VACANT' => ['Vinculación laboral', 'Ver la lista de vacantes', self::KIND_ACTION, null],
        'PS_CREATE_VACANT' => ['Vinculación laboral', 'Crear vacantes', self::KIND_ACTION, null],
        'PS_EDIT_VACANT' => ['Vinculación laboral', 'Editar vacantes', self::KIND_ACTION, null],
        'PS_APPLICATION' => ['Vinculación laboral', 'Entrar a postulaciones', self::KIND_PAGE, 'PS_GROUP_JOBS'],
        'PS_READ_APPLICATION' => ['Vinculación laboral', 'Ver la lista de postulaciones', self::KIND_ACTION, null],
        'PS_EDIT_APPLICATION' => ['Vinculación laboral', 'Editar postulaciones', self::KIND_ACTION, null],

        'PS_GROUP_SCHOLARSHIPS' => ['Becas', 'Mostrar el menú Becas', self::KIND_MENU_GROUP, null],
        'PS_SCHOLARSHIPS_ATENCION' => ['Becas', 'Entrar a la verificación de refrendos', self::KIND_PAGE, 'PS_GROUP_SCHOLARSHIPS'],
        'PS_SCHOLARSHIPS_PEDAGOGIA' => ['Becas', 'Entrar a la aprobación de refrendos', self::KIND_PAGE, 'PS_GROUP_SCHOLARSHIPS'],

        'PS_GROUP_CONFIG' => ['Configuración', 'Mostrar el menú Configuración', self::KIND_MENU_GROUP, null],
        'PS_GENERATIONS' => ['Configuración', 'Entrar a generaciones', self::KIND_PAGE, 'PS_GROUP_CONFIG'],
        'PS_ROLES' => ['Configuración', 'Entrar a roles de planes de empresa', self::KIND_PAGE, 'PS_GROUP_CONFIG'],
        'PS_GRAPHICS' => ['Configuración', 'Entrar a áreas e informes', self::KIND_PAGE, 'PS_GROUP_CONFIG'],
        'PS_NOTICES' => ['Configuración', 'Entrar a avisos', self::KIND_PAGE, 'PS_GROUP_CONFIG'],
        self::MANAGE_ADMINS => ['Configuración', 'Administrar administradores y roles del personal (solo ROOT)', self::KIND_PAGE, 'PS_GROUP_CONFIG'],
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
        return array_keys(self::MAP);
    }

    /**
     * @return array{module: string, description: string, kind: string, menu_group: ?string}
     */
    public static function metaFor(string $name): array
    {
        if (!isset(self::MAP[$name])) {
            return [
                'module' => self::UNKNOWN_MODULE,
                'description' => $name,
                'kind' => self::KIND_ACTION,
                'menu_group' => null,
            ];
        }

        [$module, $description, $kind, $menuGroup] = self::MAP[$name];

        return ['module' => $module, 'description' => $description, 'kind' => $kind, 'menu_group' => $menuGroup];
    }

    /**
     * Grantable permissions grouped by module, in map order, unknown ones in "Otros" last.
     *
     * @return array<int, array{module: string, permissions: array<int, array<string, mixed>>}>
     */
    public static function grouped(): array
    {
        $position = array_flip(self::mappedNames());
        $permissions = self::grantableQuery()->get(['id', 'name'])
            ->sortBy(fn ($p) => $position[$p->name] ?? PHP_INT_MAX)
            ->values();

        $modules = [];
        foreach ($permissions as $permission) {
            $meta = self::metaFor($permission->name);
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
            $result[] = ['module' => $module, 'permissions' => $items];
        }

        // usort is stable on PHP 8: only "Otros" moves, to the end.
        usort($result, fn ($a, $b) => ($a['module'] === self::UNKNOWN_MODULE) <=> ($b['module'] === self::UNKNOWN_MODULE));

        return $result;
    }
}
