<?php

use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\ImageController;
use App\Http\Controllers\API\CurriculumController;
use App\Http\Controllers\API\PDFController;
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

    //GENERATE PDF
    Route::get('fetchPDF', [PDFController::class, 'generatePDF']);

    // WORK EXPERIENCE TABLE
    Route::post('createWork', [CurriculumController::class, 'storeWorkExperience']);
    Route::post('UpdateWork', [CurriculumController::class, 'updateWorkExperience']);
    Route::delete('deleteWork/{id}', [CurriculumController::class, 'destroyWorkExperience']);

    // ACADEMIC INFORMATION TABLE
    Route::post('createAcademic', [CurriculumController::class, 'storeAcademicInformation']);
    Route::post('updateAcademic', [CurriculumController::class, 'updateAcademicInformation']);
    Route::delete('deleteAcademic/{id}', [CurriculumController::class, 'destroyAcademicInformatio']);

    // CONTINUING EDUCATION TABLE
    Route::post('createEducation', [CurriculumController::class, 'storeContinuingEducation']);
    Route::post('updateEducation', [CurriculumController::class, 'updateContinuingEducation']);
    Route::delete('deleteEducation/{id}', [CurriculumController::class, 'destroyContinuingEducation']);

    // CONTINUING EDUCATION TABLE
    Route::post('createKnowledge', [CurriculumController::class, 'storeTechnicalKnowledge']);
    Route::post('updateKnowledge', [CurriculumController::class, 'updateTechnicalKnowledge']);
    Route::delete('deleteKnowledge/{id}', [CurriculumController::class, 'destroyTechnicalKnowledge']);

    // CURRICULUM STATUS
    Route::post('updateStatusCV', [CurriculumController::class, 'updateCurriculumStatus']);
});
