<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeMail;

class GraduateController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // $user = auth()->user();
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        // Si el usuario pertenece al campus "MERIDA", obtiene todos los registros
        if ($user->isRoot()) {
            $data = User::where('user_type', 'BEC_INACTIVE')->get();
        } else {
            // Si no, solo obtiene los registros de su campus
            $data = User::where('campus', $user->campus,)->where('user_type', 'BEC_INACTIVE')->get();
        }

        return response()->json([
            'res' => true,
            'graduates' => $data
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
        $data = $request->validate(User::createRulesUser());
        $existingUser = User::where('enrollment', $data['enrollment'])->first();

        if (!$existingUser) {
            $data['active'] = true;
            $data['user_type'] = 'BEC_INACTIVE';
            $data['password'] = Hash::make($data['password']);

            $user = User::create($data);

            $mail = new WelcomeMail($user);
            Mail::to($user->email)->send($mail);

            return response()->json([
                'res' => true,
                'msg' => 'Usuario creado con éxito',
                'createGraduate' => $user,
            ], 201);
        }

        return response()->json([
            'res' => false,
            'msg' => 'La matrícula ya está registrada.',
        ], 409);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $user = User::findOrFail($id);

        return response()->json([
            'user' => $user
        ]);
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
        $user = User::findOrFail($id);
        $data = $request->validate(User::updateRulesUser($user->id));

        if ($request->filled('password')) {
            $data['password'] = Hash::make($data['password']);
        }

        if ($request->has('active')) {
            $data['active'] = (bool) $request->active;
        }

        $user->fill($data)->save();

        return response()->json([
            'res' => true,
            'msg' => 'Usuario actualizado correctamente',
            'updateGraduate' => $user
        ], 200);
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
