<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScholarshipSemesterGrade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ScholarshipSemesterGradeController extends Controller
{
    public function index(int $userId)
    {
        $grades = ScholarshipSemesterGrade::where('user_id', $userId)
            ->orderByDesc('semester_year')
            ->orderByDesc('semester_period')
            ->get();

        return response()->json(['res' => true, 'data' => $grades]);
    }

    public function upsert(Request $request, int $userId)
    {
        $data = $request->validate([
            'semester_year'   => 'required|integer|min:2020|max:2100',
            'semester_period' => 'required|integer|in:1,2',
            'grade'           => 'nullable|numeric|min:0|max:100',
            'file'            => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $attrs = [
            'grade'          => $data['grade'] ?? null,
            'uploaded_by_id' => auth()->id(),
        ];

        if ($request->hasFile('file')) {
            $existing = ScholarshipSemesterGrade::where('user_id', $userId)
                ->where('semester_year', $data['semester_year'])
                ->where('semester_period', $data['semester_period'])
                ->first();

            if ($existing?->file_path) {
                Storage::disk('public')->delete($existing->file_path);
            }

            $file = $request->file('file');
            $path = $file->store("scholarships/{$userId}/grades", 'public');

            $attrs['file_path']     = $path;
            $attrs['original_name'] = $file->getClientOriginalName();
            $attrs['mime_type']     = $file->getMimeType();
            $attrs['file_size']     = $file->getSize();
        }

        $grade = ScholarshipSemesterGrade::updateOrCreate(
            [
                'user_id'         => $userId,
                'semester_year'   => $data['semester_year'],
                'semester_period' => $data['semester_period'],
            ],
            $attrs
        );

        return response()->json(['res' => true, 'data' => $grade]);
    }

    public function destroy(int $id)
    {
        $grade = ScholarshipSemesterGrade::findOrFail($id);

        if ($grade->file_path) {
            Storage::disk('public')->delete($grade->file_path);
        }

        $grade->delete();

        return response()->json(['res' => true]);
    }
}
