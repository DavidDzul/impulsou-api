<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff (type ADMIN user, web ADMIN role or no role) accounts managed from psicol-panel.
 * Gate: ps_permission:PS_MANAGE_ADMINS (ROOT only). Guards run before validation (no FormRequest).
 */
class StaffAdministratorController extends Controller
{
    public function index(Request $request)
    {
        $administrators = $this->staffScope()
            ->with('roles')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'res' => true,
            'administrators' => $administrators
                ->map(fn (User $user) => $this->present($user, $request->user()))
                ->values(),
        ]);
    }

    public function show(Request $request, $id)
    {
        $user = $this->staffScope()->with('roles')->find($id);
        abort_if($user === null, 404, 'Administrador no encontrado.');

        return response()->json(['res' => true, 'administrator' => $this->present($user, $request->user())]);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        $data = $request->validate(User::createRulesStaffAdministrator());
        $actor->assertCanAssignCampus($data['campus']);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'user_type' => 'ADMIN',
                'active' => true,
                'phone' => '',
                'campus' => $data['campus'],
            ]);
            $user->syncRoles([Role::find($data['role_id'])]);

            return $user;
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'res' => true,
            'administrator' => $this->present($user->load('roles'), $actor),
        ], 201);
    }

    /**
     * ADMIN users without an ADMINISTRATION-type role that either hold a web ADMIN role or hold no role at all.
     */
    private function staffScope(): Builder
    {
        return User::where('user_type', 'ADMIN')
            ->whereDoesntHave('roles', fn ($q) => $q->where('type', 'ADMINISTRATION'))
            ->where(function ($q) {
                $q->whereHas('roles', fn ($r) => $r->where('type', 'ADMIN')->where('guard_name', 'web'))
                    ->orWhereDoesntHave('roles');
            });
    }

    private function present(User $user, User $actor): array
    {
        $role = $user->roles->first();
        $isRoot = $user->hasRole('ROOT'); // never isRoot(): it also matches ROOT_ADMINISTRATION

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'campus' => $user->campus,
            'active' => (bool) $user->active,
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'is_obsolete' => $role->name === 'YUCATAN',
            ] : null,
            'is_root' => $isRoot,
            'is_editable' => !$isRoot && $user->id !== $actor->id,
        ];
    }
}
