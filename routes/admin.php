<?php

use App\Http\Controllers\Admin\VacantPositionController;
use Illuminate\Http\Request;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BusinessAgreementController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\GenerationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\BusinessDataController;
use App\Http\Controllers\Admin\GraduateController;
use App\Http\Controllers\Admin\JobApplicationController;
use App\Http\Controllers\Admin\RoleController;

Route::controller(AuthController::class)->group(function () {
    Route::post('login', 'login');
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum', 'user_type:ADMIN'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::apiResource('generations', GenerationController::class);

    Route::apiResource('users', UserController::class);
    Route::prefix('users')->group(function () {
        Route::put('{id}/fetchPDF', [UserController::class, 'showUserPDF']);
    });

    Route::apiResource('graduates', GraduateController::class);

    Route::apiResource('business', BusinessController::class);
    Route::prefix('business')->group(function () {
        Route::post('{id}/agreement', [BusinessController::class, 'storeBusinessAgreement']);
    });

    Route::apiResource('businessData', BusinessDataController::class);
    Route::apiResource('businessAgreement', BusinessAgreementController::class);
    Route::apiResource('roles', RoleController::class);

    Route::apiResource('vacantPositions', VacantPositionController::class);
    Route::prefix('vacantPositions')->group(function () {
        Route::put('{id}/updateVacant', [VacantPositionController::class, 'updateVacant']);
        Route::put('{id}/updatePractice', [VacantPositionController::class, 'updatePractice']);
        Route::put('{id}/updateVacantJr', [VacantPositionController::class, 'updateVacantJr']);
        Route::put('{id}/status', [VacantPositionController::class, 'updateStatus']);
        Route::put('{id}/reset', [VacantPositionController::class, 'resetStatus']);
    });

    Route::apiResource('applications', JobApplicationController::class);
});
