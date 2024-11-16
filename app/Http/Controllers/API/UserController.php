<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;

class UserController extends Controller
{
    public function index()
    {
        // $users = User::with('images')->get();
        $users = User::where('admin', false)->get();
        return response()->json($users);
    }
}