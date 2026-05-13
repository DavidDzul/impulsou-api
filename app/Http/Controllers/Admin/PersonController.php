<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeMail;

class PersonController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $rules = array_merge(User::createRulesUser(), [
            'user_type' => 'required|string|in:BEC_ACTIVE,BEC_INACTIVE',
        ]);

        $data = $request->validate($rules);

        $existingUser = User::where('enrollment', $data['enrollment'])->first();
        if ($existingUser) {
            return response()->json([
                'res' => false,
                'msg' => 'La matrícula ya está registrada.',
            ], 409);
        }

        $data['active'] = true;
        $data['password'] = Hash::make($data['password']);

        $person = User::create($data);

        Mail::to($person->email)->send(new WelcomeMail($person, true));

        return response()->json([
            'res' => true,
            'msg' => 'Persona creada con éxito',
            'createPerson' => $person,
        ], 201);
    }
}
