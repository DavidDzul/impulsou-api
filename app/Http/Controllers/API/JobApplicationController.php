<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\JobApplication;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\JobApplicationCreateMail;
use App\Models\User;
use App\Models\VacantPosition;

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

        // Buscar el email del usuario relacionado al business_id
        $userEmail = User::where('id', $request->business_id)->value('email');

        if (!$userEmail) {
            return response()->json([
                'res' => false,
                'msg' => 'No se encontró un usuario con el business_id proporcionado.',
            ], 404);
        }

        // Crear una nueva solicitud de trabajo
        $application = JobApplication::create([
            'user_id' => $request->user_id,
            'business_id' => $request->business_id,
            'vacant_id' => $request->vacant_id,
        ]);

        // Enviar el correo electrónico
        try {
            $vacant = VacantPosition::where('id', $request->vacant_id)->value('vacant_name');
            $mail = new JobApplicationCreateMail($vacant);
            Mail::to($userEmail)->send($mail);
        } catch (\Exception $e) {
            return response()->json([
                'res' => true,
                'msg' => 'Solicitud creada, pero hubo un problema al enviar el correo de notificación.',
                'data' => $application,
                'error' => $e->getMessage(),
            ], 201);
        }

        return response()->json([
            'res' => true,
            'msg' => 'Solicitud creada exitosamente y correo de notificación enviado.',
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