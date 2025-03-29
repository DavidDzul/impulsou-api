<?php

use App\Http\Controllers\API\CandidateDataController;
use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\ImageController;
use App\Http\Controllers\API\CurriculumController;
use App\Http\Controllers\API\PDFController;
use App\Http\Controllers\API\BusinessController;
use App\Http\Controllers\API\VacantController;
use App\Http\Controllers\API\JobApplicationController;
use App\Models\Curriculum;

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
    $user = $request->user();

    // Obtener el primer rol con su configuración
    $role = $user->roles()->with('configuration')->first();

    return response()->json([
        "id" => $user->id,
        "first_name" => $user->first_name,
        "last_name" => $user->last_name,
        "email" => $user->email,
        "user_type" => $user->user_type,
        "phone" => $user->phone,
        "workstation" => $user->workstation,
        "role" => $role ? [
            "name" => $role->name,
            "num_visualizations" => $role->configuration->num_visualizations ?? 0,
            "num_vacancies" => $role->configuration->num_vacancies ?? 0,
            "unlimited" => $role->configuration->unlimited ?? false,
        ] : null
    ]);
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
Route::post('enrollmentLogin', [AuthController::class, 'loginEnrollment']);

Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('updateUser', [UserController::class, 'updateUser']);

    /*** IMAGE */
    Route::post('createImage', [ImageController::class, 'uploadImage']);
    Route::delete('removeImage/{id}', [ImageController::class, 'destroy']);

    /*** GENERATE PDF */
    Route::get('fetchPDF/{id}', [PDFController::class, 'generatePDF']);
    Route::get('fetchValidatePDF/{id}', [PDFController::class, 'validateAndGeneratePDF']);

    /*** CURRICULUM */
    Route::get('fetchCurriculum', [CurriculumController::class, 'index']);
    Route::post('createPersonalData', [CurriculumController::class, 'store']);

    /*** WORK EXPERIENCE TABLE */
    Route::post('createWork', [CurriculumController::class, 'storeWorkExperience']);
    Route::post('UpdateWork', [CurriculumController::class, 'updateWorkExperience']);
    Route::delete('deleteWork/{id}', [CurriculumController::class, 'destroyWorkExperience']);

    /*** ACADEMIC INFORMATION TABLE */
    Route::post('createAcademic', [CurriculumController::class, 'storeAcademicInformation']);
    Route::post('updateAcademic', [CurriculumController::class, 'updateAcademicInformation']);
    Route::delete('deleteAcademic/{id}', [CurriculumController::class, 'destroyAcademicInformatio']);

    /*** CONTINUING EDUCATION TABLE */
    Route::post('createEducation', [CurriculumController::class, 'storeContinuingEducation']);
    Route::post('updateEducation', [CurriculumController::class, 'updateContinuingEducation']);
    Route::delete('deleteEducation/{id}', [CurriculumController::class, 'destroyContinuingEducation']);

    /*** CONTINUING EDUCATION TABLE */
    Route::post('createKnowledge', [CurriculumController::class, 'storeTechnicalKnowledge']);
    Route::post('updateKnowledge', [CurriculumController::class, 'updateTechnicalKnowledge']);
    Route::delete('deleteKnowledge/{id}', [CurriculumController::class, 'destroyTechnicalKnowledge']);

    /*** CURRICULUM STATUS */
    Route::post('updateStatusCV', [CurriculumController::class, 'updateCurriculumStatus']);

    /*** GET ALL CANDIDATES */
    Route::get('fetchCandidates', [CurriculumController::class, 'getAllCandidates']);

    /*** BUSINESS */
    Route::get('fetchBusiness', [BusinessController::class, 'index']);
    Route::get('fetchVacancies', [BusinessController::class, 'getBusinessVacancies']);
    Route::post('updateBusiness', [BusinessController::class, 'updateBusinessInformation']);

    /*** JOB APPLICATIONS */
    Route::post('createApplication', [JobApplicationController::class, 'store']);
    Route::get('fetchUserApplications', [JobApplicationController::class, 'getUserApplications']);
    Route::get('fetchBusinessApplications', [JobApplicationController::class, 'getBusinessApplications']);
    Route::delete('deleteApplication/{id}', [JobApplicationController::class, 'destroyApplication']);
    Route::put('updateStatusApplications/{id}', [JobApplicationController::class, 'updateApplicationStatus']);

    /*** VACANT POSITIONS */
    Route::get('fetchVacantList', [VacantController::class, 'index']);
    Route::get('getVacant/{id}', [VacantController::class, 'show']);
    Route::post('createVacant', [VacantController::class, 'storeVacant']);
    Route::put('updateVacant/{id}', [VacantController::class, 'updateVacant']);
    Route::post('createPractice', [VacantController::class, 'storePractice']);
    Route::put('updatePractice/{id}', [VacantController::class, 'updatePractice']);
    Route::post('createVacanteJr', [VacantController::class, 'storeVacantJr']);
    Route::put('updateVacantJr/{id}', [VacantController::class, 'updateVacantJr']);
    Route::put('updateVacantStatus/{id}', [VacantController::class, 'updateVacantStatus']);

    /** CANDIDATE DATA */
    Route::apiResource('candidateData', CandidateDataController::class);
});