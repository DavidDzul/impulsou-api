<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\JobApplication;
use Illuminate\Support\Facades\Validator;

class JobApplicationController extends Controller
{
    public function store(Request $request)
    {
        // Validar los datos de entrada
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'business_id' => 'required|exists:business_data,id',
            'vacant_id' => 'required|exists:vacant_position,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'res' => false,
                'msg' => 'Datos inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verificar si ya existe una relación entre el user_id y vacant_id
        $application = JobApplication::where('user_id', $request->user_id)
            ->where('vacant_id', $request->vacant_id)
            ->first();

        if ($application) {
            return response()->json([
                'res' => false,
                'msg' => 'Ya existe una solicitud para este usuario y vacante.',
            ], 409);
        }

        // Crear una nueva solicitud de trabajo
        $application = JobApplication::create([
            'user_id' => $request->user_id,
            'business_id' => $request->business_id,
            'vacant_id' => $request->vacant_id,
        ]);

        return response()->json([
            'res' => true,
            'msg' => 'Solicitud creada exitosamente.',
            'data' => $application,
        ], 201);
    }

    public function getUserApplications()
    {
        $userId = auth()->id();
        $result = JobApplication::where('user_id', $userId)->with(['business:id,bs_name', 'vacant:id,vacant_name'])->get();

        return response()->json([
            'applications' => $result,
        ]);
    }

    public function getBusinessApplications()
    {
        $businessId = auth()->id();
        $result = JobApplication::where('business_id', $businessId)->with(['user:id,first_name,last_name', 'vacant:id,vacant_name'])->get();

        return response()->json([
            'applications' => $result,
        ]);
    }

    public function destroyApplication($id)
    {
        $application = JobApplication::find($id);
        if ($application) {
            $application->delete();
            return response()->json([
                'res' => true,
                "msg" => "Eliminado con  éxito",
            ], 200);
        } else {
            return response()->json([
                'res' => false,
                "msg" => "Error al eliminar",
            ], 404);
        }
    }
}