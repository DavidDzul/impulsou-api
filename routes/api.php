<?php

use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\ImageController;
use App\Http\Controllers\API\CurriculumController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('auth:sanctum')->get('/user/permissions', [UserController::class, 'getPermissions']);

Route::get('storage-link', function () {
    // Artisan::call('storage:link');
    if (file_exists(public_path('storage'))) {
        return 'the public/storage directory already exists.';
    }
    app('files')->link(
        storage_path('app/public'),
        public_path('storage')
    );
    return 'the public/storage directory has been linked';
});

Route::post('login', [AuthController::class, 'login']);

Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('logout', [AuthController::class, 'logout']);

    Route::post('createImage', [ImageController::class, 'uploadImage']);
    Route::get('fetchCurriculum', [CurriculumController::class, 'index']);
    Route::post('createPersonalData', [CurriculumController::class, 'store']);

    // WORK EXPERIENCE TABLE
    Route::post('createWork', [CurriculumController::class, 'storeWorkExperience']);
    Route::post('UpdateWork', [CurriculumController::class, 'updateWorkExperience']);
    Route::delete('deleteWork/{id}', [CurriculumController::class, 'destroyWorkExperience']);

    // ACADEMIC INFORMATION TABLE
    Route::post('createAcademic', [CurriculumController::class, 'storeAcademicInformation']);
    Route::post('updateAcademic', [CurriculumController::class, 'updateAcademicInformation']);
    Route::delete('deleteAcademic/{id}', [CurriculumController::class, 'destroyAcademicInformatio']);
});
