<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScholarshipProfileRequest;
use App\Http\Requests\UpdateScholarshipProfileRequest;
use App\Models\ScholarshipProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ScholarshipProfileController extends Controller
{
    public function show(int $userId)
    {
        $profile = ScholarshipProfile::with(['user', 'grantedBy:id,first_name,last_name'])
            ->where('user_id', $userId)
            ->first();

        if (!$profile) {
            return response()->json(['res' => false, 'data' => null, 'msg' => 'Perfil no encontrado.'], 404);
        }

        return response()->json(['res' => true, 'data' => $profile]);
    }

    public function store(StoreScholarshipProfileRequest $request)
    {
        $data = $request->safe()->toArray();

        if (array_key_exists('temporary_increase_amount', $data)) {
            $data['temporary_increase_granted_by_id'] = $data['temporary_increase_amount'] !== null
                ? $request->user()->id
                : null;
        }

        $profile = ScholarshipProfile::create($data);

        return response()->json(['res' => true, 'data' => $profile->fresh('user')], 201);
    }

    public function update(UpdateScholarshipProfileRequest $request, int $userId)
    {
        $profile = ScholarshipProfile::where('user_id', $userId)->firstOrFail();

        $data = $request->safe()->except('replace_temporary_increase');

        // The temporary increase is an atomic block of 4 columns + who
        // granted it. The controller only writes keys present in the
        // request (mass "safe" data), so a client sending ONLY
        // `temporary_increase_amount: null` (without the other 3 fields)
        // must still clear the ENTIRE block — otherwise valid_from/
        // valid_until/reason are left "orphaned" in the DB with a stale
        // (possibly future) date, which later false-positives the "already
        // has a valid increase" check on a legitimate new grant.
        if ($request->has('temporary_increase_amount')) {
            if ($request->input('temporary_increase_amount') === null) {
                $data['temporary_increase_amount']         = null;
                $data['temporary_increase_valid_from']     = null;
                $data['temporary_increase_valid_until']    = null;
                $data['temporary_increase_reason']         = null;
                $data['temporary_increase_granted_by_id']  = null;
            } else {
                $data['temporary_increase_granted_by_id'] = $request->user()->id;
            }
        }

        $profile->update($data);

        return response()->json(['res' => true, 'data' => $profile->fresh(['user', 'grantedBy:id,first_name,last_name'])]);
    }

    /**
     * Guarda o actualiza la retícula del becario (fechas + archivo).
     * Acepta multipart/form-data.
     */
    public function updateReticula(Request $request, int $userId)
    {
        $data = $request->validate([
            'reticula_start_date' => 'required|date',
            'reticula_end_date'   => 'required|date|after:reticula_start_date',
            'file'                => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        $profile = ScholarshipProfile::where('user_id', $userId)->firstOrFail();

        $attrs = [
            'reticula_start_date' => $data['reticula_start_date'],
            'reticula_end_date'   => $data['reticula_end_date'],
        ];

        if ($request->hasFile('file')) {
            if ($profile->reticula_file_path) {
                Storage::disk('public')->delete($profile->reticula_file_path);
            }

            $file = $request->file('file');
            $path = $file->store("scholarships/{$userId}/reticula", 'public');

            $attrs['reticula_file_path']     = $path;
            $attrs['reticula_original_name'] = $file->getClientOriginalName();
        }

        $profile->update($attrs);

        return response()->json(['res' => true, 'data' => $profile->fresh()]);
    }
}
