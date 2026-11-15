<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\CycleCutBlockedException;
use App\Models\User;
use App\Services\Users\UserDeactivationService;
use App\Models\BusinessData;
use App\Models\BusinessAgreement;
use Illuminate\Support\Facades\Hash;
use App\Models\UserBusinessMap;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeMail;

class BusinessController extends Controller
{
    /**
     * Resolves a BUSINESS user through the staff visibility scope. Out-of-scope, non-BUSINESS and
     * missing ids are indistinguishable (404). Not findOrFail(): the app handler maps
     * ModelNotFoundException to 400.
     */
    private function visibleBusinessOrNotFound(Request $request, string $id, array $with = []): User
    {
        $business = User::businessVisibleTo($request->user())->with($with)->find($id);

        abort_if($business === null, 404);

        return $business;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        $query = User::businessVisibleTo($user)->with(['businessData:id,user_id,bs_name']);

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
        $forcedCampus = User::forcedBusinessCampusFor($request->user());
        if ($forcedCampus !== null) {
            $user['campus'] = $forcedCampus;
        }
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

    public function show(Request $request, string $id): JsonResponse
    {
        $business = $this->visibleBusinessOrNotFound($request, $id, ['roles']);

        $business->role = $business->roles->first();
        $business->makeHidden('roles');

        return response()->json([
            'business' => $business
        ]);
    }


    public function update(Request $request, $id, UserDeactivationService $deactivation)
    {
        $user = $this->visibleBusinessOrNotFound($request, $id);
        $data = $request->validate(User::updateRulesBusiness($user->id));

        $forcedCampus = User::forcedBusinessCampusFor($request->user());
        if ($forcedCampus !== null && array_key_exists('campus', $data)) {
            $data['campus'] = $forcedCampus;
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($data['password']);
        }

        if ($request->has('active')) {
            $data['active'] = (bool) $request->active;
        }

        try {
            $saved = $deactivation->save($user, $data, (int) $request->user()->id);
        } catch (CycleCutBlockedException $e) {
            return $e->toConflictResponse();
        }
        $user = $saved['user'];

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
            'deactivation_effects' => $saved['effects'],
            'updateBusiness' => $userArray
        ], 200);
    }

    public function storeBusinessAgreement(Request $request, $id)
    {
        $business = $this->visibleBusinessOrNotFound($request, $id);
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
