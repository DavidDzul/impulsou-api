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
        $profile = ScholarshipProfile::with('user')
            ->where('user_id', $userId)
            ->first();

        if (!$profile) {
            return response()->json(['res' => false, 'data' => null, 'msg' => 'Perfil no encontrado.'], 404);
        }

        return response()->json(['res' => true, 'data' => $profile]);
    }

    public function store(StoreScholarshipProfileRequest $request)
    {
        $profile = ScholarshipProfile::create($request->validated());

        return response()->json(['res' => true, 'data' => $profile->fresh('user')], 201);
    }

    public function update(UpdateScholarshipProfileRequest $request, int $userId)
    {
        $profile = ScholarshipProfile::where('user_id', $userId)->firstOrFail();

        $profile->update($request->validated());

        return response()->json(['res' => true, 'data' => $profile->fresh('user')]);
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
