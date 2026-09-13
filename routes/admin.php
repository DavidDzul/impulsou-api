<?php

use App\Http\Controllers\Admin\AdministrationRoleController;
use App\Http\Controllers\Admin\AdministratorController;
use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\VacantPositionController;
use Illuminate\Http\Request;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BusinessAgreementController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\GenerationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\BusinessDataController;
use App\Http\Controllers\Admin\CandidateDataController;
use App\Http\Controllers\Admin\ClassController;
use App\Http\Controllers\Admin\GraduateController;
use App\Http\Controllers\Admin\JobApplicationController;
use App\Http\Controllers\Admin\NoticeController;
use App\Http\Controllers\Admin\PersonController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ScholarshipDocumentController;
use App\Http\Controllers\Admin\ScholarshipPaymentDataController;
use App\Http\Controllers\Admin\ScholarshipProfileController;
use App\Http\Controllers\Admin\ScholarshipRefrendController;
use App\Http\Controllers\Admin\ScholarshipSemesterGradeController;
use App\Http\Controllers\Admin\ScholarshipWithholdingController;

Route::controller(AuthController::class)->group(function () {
    Route::post('login', 'login');
});

Route::middleware('auth:sanctum')->get('/admin', function (Request $request) {
    return $request->user()->load('roles');
});

Route::middleware('auth:sanctum')->get('/permissions', [AuthController::class, 'getPermissions']);

Route::middleware(['auth:sanctum', 'user_type:ADMIN'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('updateProfile', [AuthController::class, 'updateProfile']);
    Route::get('search', [BusinessController::class, 'searchBusinesses']);

    Route::apiResource('generations', GenerationController::class);
    Route::get('filterUsers', [UserController::class, 'getUsersByFilters']);

    Route::prefix('users')->group(function () {
        Route::put('{id}/fetchPDF', [UserController::class, 'showUserPDF']);
    });
    Route::apiResource('users', UserController::class);

    Route::apiResource('graduates', GraduateController::class);

    Route::post('persons', [PersonController::class, 'store']);
    Route::post('persons/{userId}/graduate', [PersonController::class, 'graduate']);

    Route::prefix('business')->group(function () {
        Route::post('{id}/agreement', [BusinessController::class, 'storeBusinessAgreement']);
    });
    Route::apiResource('business', BusinessController::class);

    Route::apiResource('businessData', BusinessDataController::class);
    Route::apiResource('businessAgreement', BusinessAgreementController::class);

    Route::prefix('roles')->group(function () {
        Route::get('permissions', [RoleController::class, 'getAllPermissions']);
    });
    Route::apiResource('roles', RoleController::class);

    Route::prefix('vacantPositions')->group(function () {
        Route::post('{id}/storeVacant', [VacantPositionController::class, 'storeVacant']);
        Route::put('{id}/updateVacant', [VacantPositionController::class, 'updateVacant']);
        Route::post('{id}/storePractice', [VacantPositionController::class, 'storePractice']);
        Route::put('{id}/updatePractice', [VacantPositionController::class, 'updatePractice']);
        Route::post('{id}/storeVacantJr', [VacantPositionController::class, 'storeVacantJr']);
        Route::put('{id}/updateVacantJr', [VacantPositionController::class, 'updateVacantJr']);
        Route::put('{id}/status', [VacantPositionController::class, 'updateStatus']);
        Route::put('{id}/reset', [VacantPositionController::class, 'resetStatus']);
    });
    Route::apiResource('vacantPositions', VacantPositionController::class);


    Route::apiResource('applications', JobApplicationController::class);
    Route::apiResource('areas', AreaController::class);
    Route::apiResource('candidateData', CandidateDataController::class);

    Route::prefix('class')->group(function () {
        Route::get('{id}/attendances', [ClassController::class, 'getAttendanceByClass']);
        Route::get('{id}/pdf', [ClassController::class, 'pdf']);
        Route::get('{id}/sheet', [ClassController::class, 'sheet']);
        Route::post('reportPDF', [ClassController::class, 'generalReport']);
        Route::post('reportExcel', [ClassController::class, 'generalReportExcel']);
    });
    Route::apiResource('class', ClassController::class);


    Route::apiResource('attendance', AttendanceController::class);
    Route::prefix('attendance')->group(function () {
        Route::post('check-in', [AttendanceController::class, 'checkIn']);
    });

    Route::apiResource('notices', NoticeController::class);

    // ── Scholarship Profiles ──────────────────────────────────────────────────
    Route::prefix('scholarship-profiles')->group(function () {
        Route::get('{userId}', [ScholarshipProfileController::class, 'show']);
        Route::post('/', [ScholarshipProfileController::class, 'store']);
        Route::put('{userId}', [ScholarshipProfileController::class, 'update']);
        Route::post('{userId}/reticula', [ScholarshipProfileController::class, 'updateReticula']);
    });

    // ── Semester Grades ───────────────────────────────────────────────────────
    Route::prefix('users/{userId}/semester-grades')->group(function () {
        Route::get('/', [ScholarshipSemesterGradeController::class, 'index']);
        Route::post('/', [ScholarshipSemesterGradeController::class, 'upsert']);
    });
    Route::delete('semester-grades/{id}', [ScholarshipSemesterGradeController::class, 'destroy']);

    // ── Scholarship Refrends ──────────────────────────────────────────────────
    Route::prefix('scholarship-refrends')->group(function () {
        Route::get('/', [ScholarshipRefrendController::class, 'index']);
        // Bulk and static routes MUST come before {refrend} to prevent route shadowing
        Route::get('bulk-table', [ScholarshipRefrendController::class, 'bulkTable']);
        Route::post('generate', [ScholarshipRefrendController::class, 'generate']);
        Route::post('generate/{userId}', [ScholarshipRefrendController::class, 'generateForUser']);
        Route::post('bulk/approve', [ScholarshipRefrendController::class, 'bulkApprove']);
        Route::post('bulk/notify', [ScholarshipRefrendController::class, 'bulkNotify']);
        Route::post('bulk/pay', [ScholarshipRefrendController::class, 'bulkPay']);
        // Single-refrend routes (model binding)
        Route::get('{refrend}', [ScholarshipRefrendController::class, 'show']);
        Route::post('{refrend}/approve-full', [ScholarshipRefrendController::class, 'approveFullPayment']);
        Route::post('{refrend}/situation', [ScholarshipRefrendController::class, 'recordSituation']);
        Route::post('{refrend}/clear-resolution', [ScholarshipRefrendController::class, 'clearResolution']);
        Route::post('{refrend}/atencion-flag', [ScholarshipRefrendController::class, 'atencionFlag']);
        Route::post('{refrend}/atencion-clear', [ScholarshipRefrendController::class, 'atencionClearFlag']);
        Route::post('{refrend}/pedagogia-resolve', [ScholarshipRefrendController::class, 'pedagogiaResolve']);
        Route::post('{refrend}/notify', [ScholarshipRefrendController::class, 'notifyStudent']);
        Route::patch('{refrend}/inline', [ScholarshipRefrendController::class, 'patchInline']);
        Route::post('{refrend}/recalculate', [ScholarshipRefrendController::class, 'recalculate']);
        Route::put('{refrend}/discharge', [ScholarshipRefrendController::class, 'discharge']);
        Route::post('{refrend}/incidents', [ScholarshipRefrendController::class, 'createIncident']);
        Route::delete('{refrend}/incidents/{incident}', [ScholarshipRefrendController::class, 'deleteIncident']);
        Route::patch('{refrend}/incidents/{incident}/resolve', [ScholarshipRefrendController::class, 'resolveIncident']);
    });

    Route::get('users/{userId}/scholarship-refrends', [ScholarshipRefrendController::class, 'forUser']);
    Route::get('users/{userId}/attendance-summary', [ScholarshipRefrendController::class, 'attendanceSummary']);
    Route::get('users/{userId}/scholarship-withholdings', [ScholarshipWithholdingController::class, 'index']);
    Route::prefix('scholarship-withholdings')->group(function () {
        Route::patch('{withholding}/payments/{payment}/void', [ScholarshipWithholdingController::class, 'voidPayment']);
    });

    // ── Student Documents ─────────────────────────────────────────────────────
    Route::prefix('scholarship-documents')->group(function () {
        Route::get('user/{userId}', [ScholarshipDocumentController::class, 'index']);
        Route::post('/', [ScholarshipDocumentController::class, 'store']);
        Route::patch('{id}', [ScholarshipDocumentController::class, 'update']);
        Route::delete('{id}', [ScholarshipDocumentController::class, 'destroy']);
    });

    // ── Scholarship Payment Data (bank account, CURP, RFC) ──────────────────
    // design D4/D5 (obs #1583): permission: is an ADDITIONAL, more granular
    // gate layered on top of auth:sanctum + user_type:ADMIN above — this is
    // the ONLY route group in this file using it (spec R7 non-goal).
    Route::prefix('scholarship-payment-data')->group(function () {
        Route::get('{userId}', [ScholarshipPaymentDataController::class, 'show'])
            ->middleware('permission:ADM_READ_PAYMENT_DATA');
        Route::post('/', [ScholarshipPaymentDataController::class, 'store'])
            ->middleware('permission:ADM_EDIT_PAYMENT_DATA');
        Route::put('{userId}', [ScholarshipPaymentDataController::class, 'update'])
            ->middleware('permission:ADM_EDIT_PAYMENT_DATA');
    });

    // ── Control: Roles (administration-panel) ───────────────────────────────
    // design obs #1593: prefix `administration-roles` (not `roles`) to avoid
    // colliding with RoleController's existing `apiResource('roles', ...)`.
    // Static `permissions` route MUST come before `{id}` to avoid shadowing,
    // same convention as `scholarship-refrends/bulk-table` above.
    Route::prefix('administration-roles')->group(function () {
        Route::get('/', [AdministrationRoleController::class, 'index'])
            ->middleware('permission:ADM_READ_ROLES');
        Route::get('permissions', [AdministrationRoleController::class, 'permissionsCatalog'])
            ->middleware('permission:ADM_READ_ROLES');
        Route::get('{id}', [AdministrationRoleController::class, 'show'])
            ->middleware('permission:ADM_READ_ROLES');
        Route::post('/', [AdministrationRoleController::class, 'store'])
            ->middleware('permission:ADM_MANAGE_ROLES');
        Route::put('{id}/permissions', [AdministrationRoleController::class, 'syncPermissions'])
            ->middleware('permission:ADM_MANAGE_ROLES');
    });

    // ── Control: Accesos (administration-panel) ─────────────────────────────
    // design obs #1593: prefix `administrators` (not `users`) to avoid
    // colliding with UserController's existing `apiResource('users', ...)`.
    Route::prefix('administrators')->group(function () {
        Route::get('/', [AdministratorController::class, 'index'])
            ->middleware('permission:ADM_READ_ADMINS');
        Route::get('{id}', [AdministratorController::class, 'show'])
            ->middleware('permission:ADM_READ_ADMINS');
        Route::post('/', [AdministratorController::class, 'store'])
            ->middleware('permission:ADM_MANAGE_ADMINS');
        Route::put('{id}/role', [AdministratorController::class, 'assignRole'])
            ->middleware('permission:ADM_MANAGE_ADMINS');
    });
});
