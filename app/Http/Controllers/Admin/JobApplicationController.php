<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use Illuminate\Http\Request;

class JobApplicationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        $data = JobApplication::with([
            'business:id,bs_name',
            'vacant:id,vacant_name,campus',
            'user:id,first_name,last_name'
        ])
            ->whereHas('vacant', function ($query) use ($user) {
                $query->where('campus', $user->campus);
            })
            ->get();

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