<?php

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

// Rutas para usuarios ADMIN
// Route::middleware(['auth:sanctum', 'user_type:ADMIN'])->group(function () {
// });

Route::post('login', [AuthController::class, 'login']);
Route::post('enrollmentLogin', [AuthController::class, 'loginEnrollment']);

Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('updateUser', [AuthController::class, 'updateUser']);

    Route::post('createImage', [ImageController::class, 'uploadImage']);
    Route::get('fetchCurriculum', [CurriculumController::class, 'index']);
    Route::post('createPersonalData', [CurriculumController::class, 'store']);

    //GENERATE PDF
    Route::get('fetchPDF/{id}', [PDFController::class, 'generatePDF']);
    Route::get('fetchValidatePDF/{id}', [PDFController::class, 'validateAndGeneratePDF']);

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
    // GET ALL CANDIDATES
    Route::get('fetchCandidates', [CurriculumController::class, 'getAllCandidates']);

    // BUSINESS
    Route::get('fetchBusiness', [BusinessController::class, 'index']);
    Route::get('fetchVacancies', [BusinessController::class, 'getAllvacancies']);
    Route::post('updateBusiness', [BusinessController::class, 'updateBusinessInformation']);
    Route::post('createVacant', [BusinessController::class, 'storeVacantPosition']);
    Route::post('createPractice', [BusinessController::class, 'storePracticeVacant']);
    Route::post('updateVacant', [BusinessController::class, 'updateVacantPosition']);
    Route::post('updatePractice', [BusinessController::class, 'updatePracticeVacant']);
    Route::delete('deleteVacant/{id}', [BusinessController::class, 'destroyVacantPosition']);
    Route::post('updateStatusVacant/{id}', [BusinessController::class, 'updateVacantPositionStatus']);

    //JOB APPLICATIONS
    Route::post('createApplication', [JobApplicationController::class, 'store']);
    Route::get('fetchUserApplications', [JobApplicationController::class, 'getUserApplications']);
    Route::get('fetchBusinessApplications', [JobApplicationController::class, 'getBusinessApplications']);
    Route::delete('deleteApplication/{id}', [JobApplicationController::class, 'destroyApplication']);
    Route::post('updateStatusApplications/{id}/status', [JobApplicationController::class, 'updateApplicationStatus']);

    Route::get('fetchVacantList', [VacantController::class, 'getVacantList']);
    Route::get('getVacant/{id}', [VacantController::class, 'showVacant']);
});
