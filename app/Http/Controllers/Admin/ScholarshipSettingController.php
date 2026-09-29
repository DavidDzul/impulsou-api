<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateScholarshipSettingRequest;
use App\Models\ScholarshipSetting;
use Illuminate\Http\JsonResponse;

/**
 * Global, reference-only Telmex base amount (sdd/scholarship-telmex-iu-split,
 * design D8). `telmex_base_amount` is display-only — it is NEVER read by
 * buildSnapshot(), validation, or any auto-fill logic.
 *
 * show() has NO permission gate (mirrors the scholarship-profile config GET
 * precedent, design D9 rationale): it is shared reference data needed by
 * anyone who can open the profile dialog, in both administration-panel and
 * psicol-panel. update() is gated behind `ADM_MANAGE_SCHOLARSHIP_SETTINGS`
 * (route middleware).
 */
class ScholarshipSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['res' => true, 'data' => ScholarshipSetting::current()]);
    }

    public function update(UpdateScholarshipSettingRequest $request): JsonResponse
    {
        $setting = ScholarshipSetting::current();
        $setting->update($request->validated());

        return response()->json(['res' => true, 'data' => $setting->fresh()]);
    }
}
