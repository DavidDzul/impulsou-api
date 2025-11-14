<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use Illuminate\Http\Request;
use App\Models\UserBusinessMap;

class JobApplicationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        // Consulta base
        $query = JobApplication::with([
            'business:id,user_id,bs_name',
            'vacant:id,vacant_name,campus',
            'user:id,first_name,last_name,campus'
        ]);

        // 🟢 1. ROOT o ROOT_JOB → ven todo (sin filtros)
        if (!($user->isRoot() || $user->isRootJob())) {

            // 🟡 2. Rol YUCATAN → filtrar por business asignados
            if ($user->hasRole('YUCATAN')) {
                $businessIds = UserBusinessMap::where('user_id', $user->id)
                    ->pluck('business_id');

                $query->whereIn('business_id', $businessIds);
            }
            // 🔵 3. Usuario normal → campus del postulante
            else if ($user->campus !== 'MERIDA') {
                $query->whereHas('user', function ($query) use ($user) {
                    $query->where('campus', $user->campus);
                });
            }
        }

        // Datos finales
        $data = $query->get();

        return response()->json([
            'res' => true,
            'applications' => $data
        ]);
    }




    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();
        $userType = in_array($user->user_type, ['BUSINESS', 'ADMIN']) ? $user->user_type : 'USER';

        $data = $request->validate(JobApplication::rejectedRules());
        $application = JobApplication::findOrFail($id);

        if ($application->status !== 'PENDING') {
            return response()->json([
                'res' => false,
                'msg' => 'Solo se puede actualizar el estado si la postulación está pendiente.',
            ], 403);
        }

        if ($data['status'] === 'REJECTED') {
            $application->rejected_reason = $data['rejected_reason'];
            $application->rejected_other = $data['rejected_other'] ?? "";
        }

        $application->rejected_by = $userType;
        $application->status = $data['status'];
        $application->save();

        $application->load(['business:id,bs_name', 'vacant:id,vacant_name', 'user:id,first_name,last_name']);

        return response()->json([
            'res' => true,
            'msg' => 'Estado de la postulación actualizado exitosamente.',
            'application' => $application,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
