<?php

namespace App\Models;

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
            'permissions_ids'    => 'array',
            'permissions_ids.*'  => 'exists:permissions,id',
        ];
    }

    public function configuration()
    {
        return $this->hasOne(RoleConfiguration::class, 'role_id');
    }

    /**
     * Validation rules for creating a Control (administration-panel) role.
     *
     * `name` is unique only among type='ADMINISTRATION' roles, not globally —
     * the `roles` table already has USER-type rows sharing similarly-shaped
     * names (ROOT, ROOT_CAMPUS, etc.). `type` is intentionally NOT a
     * validated/accepted field here: it is always hardcoded server-side by
     * the controller (design obs #1593, spec obs #1592 R2).
     */
    public static function createAdministrationRules()
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('type', 'ADMINISTRATION')],
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
