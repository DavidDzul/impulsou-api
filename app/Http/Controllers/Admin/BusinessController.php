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
        $user['password'] = Hash::make($user['password']);
        $user['user_type'] = 'BUSINESS';
        $user['active'] = true;

        $createData = User::create($user);

        $business = $request->validate(BusinessData::createRules());
        $business['user_id'] = $createData->id;
        BusinessData::create($business);

        $agreement = $request->validate(BusinessAgreement::createRules());
        $agreement['user_id'] = $createData->id;
        BusinessAgreement::create($agreement);

        // Asignar el rol al usuario
        $createData->assignRole($request->role);

        // Cargar relaciones
        $createData->load(['businessData', 'businessAgreement']);

        return response()->json([
            'res' => true,
            'msg' => 'Empresa registrada correctamente.',
            'createBusiness' => $createData
        ], 201);
    }

    // // Crear convenio con duración de 1 año
    // CompanyAgreement::create([
    //     "company_id" => $company->id,
    //     "start_date" => Carbon::now(),
    //     "end_date" => Carbon::now()->addYear(),
    // ]);

    // return response()->json(["msg" => "Empresa registrada con éxito"]);
}