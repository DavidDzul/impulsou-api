<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use App\Models\BusinessData;
use App\Models\BusinessAgreement;
use Illuminate\Support\Facades\Hash;
use App\Models\UserBusinessMap;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeMail;

class BusinessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        $query = User::with(['businessData:id,user_id,bs_name'])
            ->where('user_type', 'BUSINESS');

        // Si es ROOT o ROOT_JOB → puede ver todo
        if (!($user->isRoot() || $user->isRootJob())) {

            // Si es YUCATAN → filtrar por business asignados
            if ($user->hasRole('YUCATAN')) {
                $businessIds = UserBusinessMap::where('user_id', $user->id)
                    ->pluck('business_id');

                $query->whereIn('id', $businessIds);
            } else {
                // Si NO es ROOT, NO es ROOT_JOB y NO es YUCATAN → campus normal
                $query->where('campus', $user->campus);
            }
        }

        $data = $query->get()->map(function ($user) {
            $user->role = $user->mainRole();
            $user->makeHidden('roles');
            return $user;
        });

        return response()->json([
            'res' => true,
            'business' => $data
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->validate(User::createRulesBusiness());
        $business = $request->validate(BusinessData::createRules());
        $agreement = $request->validate(BusinessAgreement::createRules());

        $user['password'] = Hash::make($user['password']);
        $user['user_type'] = 'BUSINESS';
        $user['active'] = true;

        $createData = User::create($user);
        $business['user_id'] = $createData->id;
        $agreement['user_id'] = $createData->id;

        BusinessData::create($business);
        BusinessAgreement::create($agreement);

        $createData->assignRole($request->role);

        $createData->load(['roles', 'businessData', 'businessAgreement']);
        $role = $createData->roles->first();

        $userArray = $createData->toArray();
        unset($userArray['roles']);

        $userArray['role'] = $role;

        $mail = new WelcomeMail($createData, false);
        Mail::to($createData->email)->send($mail);

        return response()->json([
            'res' => true,
            'msg' => 'Empresa registrada correctamente.',
            'createBusiness' => $userArray
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $business = User::with(['roles'])->findOrFail($id);

        $business->role = $business->roles->first();
        $business->makeHidden('roles');

        return response()->json([
            'business' => $business
        ]);
    }


    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validate(User::updateRulesBusiness($user->id));

        if ($request->filled('password')) {
            $data['password'] = Hash::make($data['password']);
        }

        if ($request->has('active')) {
            $data['active'] = (bool) $request->active;
        }

        $user->fill($data)->save();

        if ($request->filled('role')) {
            $user->syncRoles([$request->input('role')]);
        }

        $user->load('roles');
        $role = $user->roles->first();

        $userArray = $user->toArray();
        unset($userArray['roles']);

        $userArray['role'] = $role;

        return response()->json([
            'res' => true,
            'msg' => 'Usuario actualizado correctamente',
            'updateBusiness' => $userArray
        ], 200);
    }

    public function storeBusinessAgreement(Request $request, $id)
    {
        $business = User::findOrFail($id);
        $agreement = $request->validate(BusinessAgreement::createRules());
        $agreement['user_id'] = $business->id;

        $data = BusinessAgreement::create($agreement);

        return response()->json([
            'res' => true,
            'msg' => 'Convenio registrado correctamente.',
            'agreement' => $data
        ], 201);
    }

    public function searchBusinesses(Request $request)
    {
        $search = $request->input('search');
        $businesses = BusinessData::where('bs_name', 'LIKE', "%{$search}%")
            ->select('id', 'bs_name', 'user_id')
            ->limit(10)
            ->get();
        return response()->json(['businesses' => $businesses]);
    }
}
