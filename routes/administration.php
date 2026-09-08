<?php

use App\Http\Controllers\Administration\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->group(function () {
    Route::post('login', 'login');
});

Route::middleware('auth:sanctum')->get('/administration', function (Request $request) {
    return $request->user()->load('roles');
});

Route::middleware(['auth:sanctum', 'user_type:ADMIN'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
});
