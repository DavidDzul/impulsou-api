<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Exceptions\UserTypeLockedException;
use App\Exceptions\CycleCutBlockedException;
use App\Models\User;
use App\Services\Users\UserDeactivationService;
use App\Support\Scholarship\UserTypeChangeGuard;
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

        $data = User::withListFlags()->personsVisibleTo($user)->where('user_type', 'BEC_INACTIVE')->get();

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

            $mail = new WelcomeMail($user, true);
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
    public function show(Request $request, $id)
    {
        $user = User::withListFlags()->personsVisibleTo($request->user())->find($id);
        abort_if(!$user, 404);

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
    public function update(Request $request, $id, UserDeactivationService $deactivation)
    {
        $user = User::personsVisibleTo($request->user())->find($id);
        abort_if(!$user, 404);
        $data = $request->validate(User::updateRulesUser($user->id));

        try {
            UserTypeChangeGuard::assertAllowed($user, $data);
        } catch (UserTypeLockedException $e) {
            return $e->toResponse();
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
        // Re-fetch with the list flags so the row the panel swaps in keeps them.
        $user = User::withListFlags()->findOrFail($user->id);

        return response()->json([
            'res' => true,
            'msg' => 'Usuario actualizado correctamente',
            'deactivation_effects' => $saved['effects'],
            'updateGraduate' => $user
        ], 200);
    }
}
