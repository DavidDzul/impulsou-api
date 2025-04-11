<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\JobApplication;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\JobApplicationCreateMail;
use App\Models\Curriculum;
use App\Models\User;
use App\Models\VacantPosition;

class JobApplicationController extends Controller
{
    public function store(Request $request)
    {

        $request->validate(JobApplication::createRules());

        $userCV = Curriculum::where('user_id', $request->user_id)
            ->first();

        if (!$userCV) {
            return response()->json([
                'res' => false,
                'msg' => 'Por favor, completa tu currículum vitae para que puedas postularte a la vacante.',
            ], 409);
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

        // Crear una nueva solicitud de trabajo
        $application = JobApplication::create([
            'user_id' => $request->user_id,
            'business_id' => $request->business_id,
            'vacant_id' => $request->vacant_id,
        ]);

        // Cargar las relaciones 'business' y 'vacant' en la solicitud creada
        $application->load(['business:id,bs_name', 'vacant:id,vacant_name']);

        // Enviar el correo electrónico
        try {
            $vacant = VacantPosition::where('id', $request->vacant_id)->value('vacant_name');
            $mail = new JobApplicationCreateMail($vacant);
            Mail::to($userEmail)->send($mail);
        } catch (\Exception $e) {
            return response()->json([
                'res' => true,
                'msg' => 'Solicitud creada, pero hubo un problema al enviar el correo de notificación.',
                'application' => $application,
                'error' => $e->getMessage(),
            ], 201);
        }

        return response()->json([
            'res' => true,
            'msg' => 'Solicitud creada exitosamente y correo de notificación enviado.',
            'application' => $application,
        ], 201);
    }


    public function getUserApplications()
    {
        $userId = auth()->id();
        $result = JobApplication::where('user_id', $userId)->with(['business:id,bs_name', 'vacant:id,vacant_name'])->orderBy('created_at', 'desc')->get();

        return response()->json([
            'applications' => $result,
        ]);
    }

    public function getBusinessApplications()
    {
        $businessId = auth()->id();
        $result = JobApplication::where('business_id', $businessId)->with(['user:id,first_name,last_name', 'vacant:id,vacant_name'])->orderBy('created_at', 'desc')->get();

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

    public function updateApplicationStatus(Request $request, $id)
    {
        $request->validate(JobApplication::rejectedRules());

        $application = JobApplication::find($id);

        if (!$application) {
            return response()->json([
                'res' => false,
                'msg' => 'Postulación no encontrada.',
            ], 404);
        }

        // Verificar si la postulación está en estado PENDING
        if ($application->status !== 'PENDING') {
            return response()->json([
                'res' => false,
                'msg' => 'Solo se puede actualizar el estado si la postulación está pendiente.',
            ], 403);
        }

        $user = auth()->user();
        $userType = in_array($user->user_type, ['BUSINESS', 'ADMIN']) ? $user->user_type : 'USER';

        if ($request->status === 'REJECTED') {
            $application->rejected_by = $userType;
            $application->rejected_reason = $request->rejected_reason;
            $application->rejected_other = $request->rejected_other ?? "";
        }

        $application->status = $request->status;
        $application->save();

        $application->load(['business:id,bs_name', 'vacant:id,vacant_name', 'user:id,first_name,last_name']);

        return response()->json([
            'res' => true,
            'msg' => 'Estado de la postulación actualizado exitosamente.',
            'application' => $application,
        ]);
    }
}
