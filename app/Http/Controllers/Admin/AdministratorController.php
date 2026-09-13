<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Control (Accesos) — administrator account management (administration-panel).
 *
 * index()/show() scope (A1, spec obs #1592, design obs #1593): accounts
 * with user_type='ADMIN' AND (holding a role with type='ADMINISTRATION' OR
 * holding zero roles at all). This excludes psicol-panel staff accounts
 * (also user_type='ADMIN' but holding a USER-type role) while still
 * surfacing freshly created administrators, who hold zero roles at
 * creation time (store() below never assigns a role).
 *
 * Role assignment (assignRole + self-demotion guard, R3/R4/A2) — PR3b.
 */
class AdministratorController extends Controller
{
    /**
     * Shared A1 query scope, used by both index() and show() so a
     * non-ADMINISTRATION admin-type account (e.g. ROOT_CAMPUS) is excluded
     * from BOTH endpoints identically — never leaked via a direct id lookup.
     */
    private function accesosScope()
    {
        return User::where('user_type', 'ADMIN')
            ->where(function ($query) {
                $query->whereHas('roles', function ($roleQuery) {
                    $roleQuery->where('type', 'ADMINISTRATION');
                })->orWhereDoesntHave('roles');
            });
    }

    public function index()
    {
        $administrators = $this->accesosScope()->with('roles')->get();

        return response()->json(['res' => true, 'administrators' => $administrators]);
    }

    public function show($id)
    {
        $administrator = $this->accesosScope()->with('roles')->find($id);

        if (!$administrator) {
            return response()->json(['res' => false, 'msg' => 'Cuenta no encontrada.'], 404);
        }

        return response()->json(['res' => true, 'administrator' => $administrator]);
    }

    /**
     * `role`/`role_id` are never read from the request, even if submitted —
     * User::createRulesAdministrator() does not list them, so
     * $request->validate() strips them before this method ever sees the
     * data. The account is created with zero role assignments (spec obs
     * #1592 R3).
     */
    public function store(Request $request)
    {
        $data = $request->validate(User::createRulesAdministrator());

        $administrator = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'user_type' => 'ADMIN',
            'active' => true,
            // `phone` and `campus` have no usable DB default for this
            // account type (`phone` is NOT NULL with no default; `campus`
            // is a non-nullable enum — see
            // 2014_10_12_000001_create_users_table.php) and are out of
            // scope for administrator accounts per spec obs #1592 R3 (only
            // nombres/correo/contraseña are collected at creation).
            // 'MERIDA' mirrors UserSeeder.php's existing convention for
            // other ADMIN-type accounts (ROOT, ROOT_ADMINISTRATION, etc.)
            // that have no real campus affiliation.
            'phone' => '',
            'campus' => 'MERIDA',
        ]);

        return response()->json(['res' => true, 'administrator' => $administrator], 201);
    }

    /**
     * Assigns a single ADMINISTRATION-type role to an administrator account,
     * replacing any role the account previously held (Accesos assigns one
     * role per account — design obs #1593).
     *
     * Self-demotion guard (R4/A2, design obs #1593): checked BEFORE any
     * write. If the acting user targets their OWN account id and currently
     * holds any ADMINISTRATION-type role, the request is rejected outright
     * — regardless of which role is being submitted. This is deliberately
     * simpler than a "does the new role differ" comparison: per design's
     * exact rule ("target currently hasAnyRole with type='ADMINISTRATION' ->
     * 422 before any write"), any self-targeted write while already holding
     * an administration role is blocked, closing the edge case of a
     * self-reassignment to the identical role being treated as a no-op
     * exception. The guard keys off the acting user's id, never off role
     * name, so changing a DIFFERENT account's role is never falsely
     * blocked even if that account's current role name happens to match
     * the acting user's own.
     */
    public function assignRole(Request $request, $id)
    {
        $targetId = (int) $id;
        $actingUserId = (int) $request->user()->id;

        if ($targetId === $actingUserId) {
            $actingUserHoldsAdministrationRole = $request->user()->roles()
                ->where('type', 'ADMINISTRATION')
                ->exists();

            if ($actingUserHoldsAdministrationRole) {
                return response()->json([
                    'res' => false,
                    'msg' => 'No puedes modificar tu propia asignación de rol.',
                ], 422);
            }
        }

        $data = $request->validate(User::assignAdministrationRoleRules());

        $administrator = $this->accesosScope()->find($targetId);

        if (!$administrator) {
            return response()->json(['res' => false, 'msg' => 'Cuenta no encontrada.'], 404);
        }

        $role = Role::find($data['role_id']);
        $administrator->syncRoles([$role]);

        return response()->json(['res' => true, 'administrator' => $administrator->load('roles')]);
    }
}
