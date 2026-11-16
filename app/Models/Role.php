<?php

namespace App\Models;

use App\Support\PsPermissionCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\Rule;

class Role extends SpatieRole
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = ['name', 'type'];

    /**
     * `guard_name` is required (NOT NULL, no DB default — see
     * 2024_11_15_172149_create_permission_tables.php) but deliberately not
     * in $fillable, since it should never be client-settable. Spatie's own
     * `Role::create()` static method injects a default guard_name into the
     * attributes array before instantiating — but because `$fillable`
     * excludes it, mass assignment strips it right back out whenever an
     * `App\Models\Role` instance is what actually gets constructed (as
     * opposed to `Spatie\Permission\Models\Role`, which has no fillable
     * restriction and is what RoleSeeder.php uses directly). This event
     * sets it via direct property assignment (bypassing fillable) so
     * `Role::create(['name' => ..., 'type' => ...])` works from anywhere,
     * discovered while implementing control-accesos-administration-panel
     * PR2's `AdministrationRoleController::store()`.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $role) {
            $role->guard_name = $role->guard_name ?? config('auth.defaults.guard');
        });
    }

    /**
     * Role types the ROOT-only RoleController may list and edit: business plan
     * tiers only. Staff (ADMIN) and ADMINISTRATION roles are out of scope.
     */
    public const MANAGEABLE_TYPES = ['USER'];

    /**
     * Defense in depth: ROOT is type ADMIN (so already 404), but legacy local
     * DBs seeded before type alignment label it USER. It must never be edited here.
     */
    public const PROTECTED_ROLES = ['ROOT'];

    /**
     * Validation rules for updating a business plan (USER) role through
     * RoleController: vacancy limits plus USER-type permissions only. Any
     * `permissions_ids.*` that is missing or not type USER rejects the request.
     */
    public static function createOrUpdateRules()
    {
        return [
            'unlimited_jobs'          => 'boolean',
            'num_job_vacancies' => 'required|integer|min:0',
            'unlimited_professionals'          => 'boolean',
            'num_professional_vacancies' => 'required|integer|min:0',
            'unlimited_jr'          => 'boolean',
            'num_jr_vacancies' => 'required|integer|min:0',
            'unlimited_visualizations'          => 'boolean',
            'num_visualizations' => 'required|integer|min:0',
            // present: an omitted key must not silently wipe the tier's permissions (an explicit [] clears them).
            'permissions_ids'    => 'present|array',
            'permissions_ids.*'  => [Rule::exists('permissions', 'id')->where('type', 'USER')],
        ];
    }

    public function configuration()
    {
        return $this->hasOne(RoleConfiguration::class, 'role_id');
    }

    /** Staff roles that can never be listed, edited or assigned from the staff management endpoints. */
    public const STAFF_NON_ASSIGNABLE = ['ROOT', 'YUCATAN'];

    /** Role names that an administration role may never take (compared case-insensitively). */
    public const RESERVED_NAMES = [
        'ROOT', 'ROOT_JOB', 'ROOT_ADMINISTRATION', 'YUCATAN',
        'BASIC', 'BRONZE', 'SILVER', 'GOLD', 'PLATINUM', 'DIAMOND',
    ];

    /**
     * Validation rules for creating a Control (administration-panel) role.
     *
     * `name` must be unique across ALL roles of the web guard (the DB unique
     * index is name+guard_name, not type-scoped, so a name already used by a
     * USER/ADMIN role would otherwise surface as a 500) and may not be a
     * reserved ROOT name. `type` is intentionally NOT a validated/accepted
     * field here: it is always hardcoded server-side by the controller
     * (design obs #1593, spec obs #1592 R2).
     */
    public static function createAdministrationRules()
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (in_array(strtoupper((string) $value), self::RESERVED_NAMES, true)) {
                        $fail('Este nombre de rol está reservado.');
                    }
                },
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
        ];
    }

    /**
     * Validation rules for creating a staff (type ADMIN) role from psicol-panel. Uppercase
     * identifier, not reserved, unique among web roles ignoring case (UPPER() works on
     * SQLite and MySQL). Type and guard are hardcoded by the controller, never accepted.
     */
    public static function createStaffRules()
    {
        return [
            'name' => [
                'required',
                'string',
                'regex:/^[A-Z][A-Z0-9_]{2,49}$/',
                function ($attribute, $value, $fail) {
                    $name = strtoupper((string) $value);
                    if (in_array($name, self::RESERVED_NAMES, true)) {
                        $fail('Este nombre de rol está reservado.');
                        return;
                    }
                    $exists = self::where('guard_name', 'web')->whereRaw('UPPER(name) = ?', [$name])->exists();
                    if ($exists) {
                        $fail('Ya existe un rol con este nombre.');
                    }
                },
            ],
        ];
    }

    /**
     * Validation rules for syncing a staff role's PS_* permissions. Every id must be grantable
     * (PsPermissionCatalog::grantableQuery); one invalid id rejects the whole request.
     */
    public static function syncStaffPermissionsRules()
    {
        return [
            'permissions_ids'   => 'present|array',
            'permissions_ids.*' => [
                'bail',
                'integer',
                Rule::in(PsPermissionCatalog::grantableQuery()->pluck('id')->all()),
            ],
        ];
    }

    /**
     * Validation rules for syncing an ADMINISTRATION-type role's permission
     * checklist. Every submitted id must belong to a type='ADMINISTRATION'
     * permission — if any id fails this, the whole request is rejected
     * (422), never partially applied (spec obs #1592 R2).
     */
    public static function syncPermissionsRules()
    {
        return [
            'permissions_ids'   => 'array',
            'permissions_ids.*' => [Rule::exists('permissions', 'id')->where('type', 'ADMINISTRATION')],
        ];
    }
}
