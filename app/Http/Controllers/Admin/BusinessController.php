<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use App\Models\BusinessData;
use App\Models\BusinessAgreement;
use Illuminate\Support\Facades\Hash;

class BusinessController extends Controller
{

    public function index(): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        $query = User::with(['businessData:id,user_id,bs_name'])
            ->where('user_type', 'BUSINESS');

        if ($user->campus !== 'MERIDA') {
            $query->where('campus', $user->campus);
        }

        $data = $query->get();

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

        // Cargar relaciones
        $createData->load(['businessData', 'businessAgreement']);

        return response()->json([
            'res' => true,
            'msg' => 'Empresa registrada correctamente.',
            'createBusiness' => $createData
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $business = User::findOrFail($id);

        return response()->json([
            'business' => $business
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validate(User::updateRulesBusiness($user->id));

        if ($request->has('active')) {
            $data['active'] = (bool) $request->active;
        }

        $user->fill($data)->save();

        return response()->json([
            'res' => true,
            'msg' => 'Usuario actualizado correctamente',
            'updateBusiness' => $user
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
}
