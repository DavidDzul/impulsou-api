<?php

use App\Http\Controllers\Admin\AuthController;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->group(function () {
    Route::post('login', 'login');
});

// Rutas para usuarios ADMIN
Route::middleware(['auth:sanctum', 'user_type:ADMIN'])->group(function () {
    Route::post('test', [AuthController::class, 'test']);
});