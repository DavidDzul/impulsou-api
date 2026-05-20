<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentDocumentRequest;
use App\Models\StudentDocument;
use App\Enums\DocumentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ScholarshipDocumentController extends Controller
{
    /**
     * Lista documentos de un becario, opcionalmente filtrados por periodo.
     */
    public function index(Request $request, int $userId)
    {
        $query = StudentDocument::where('user_id', $userId)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month');

        if ($request->filled('year')) {
            $query->where('period_year', $request->query('year'));
        }

        if ($request->filled('month')) {
            $query->where('period_month', $request->query('month'));
        }

        return response()->json(['res' => true, 'data' => $query->get()]);
    }

    /**
     * Sube un documento para el becario.
     */
    public function store(StoreStudentDocumentRequest $request)
    {
        $validated = $request->validated();
        $file      = $request->file('file');

        $path = $file->store(
            "scholarships/{$validated['user_id']}/{$validated['period_year']}/{$validated['period_month']}",
            'public'
        );

        $document = StudentDocument::create([
            'user_id'       => $validated['user_id'],
            'document_type' => $validated['document_type'],
            'period_year'   => $validated['period_year'],
            'period_month'  => $validated['period_month'],
            'status'        => DocumentStatus::ACCEPTED->value,
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'file_size'     => $file->getSize(),
            'description'   => $validated['description'] ?? null,
            'observations'  => $validated['observations'] ?? null,
        ]);

        return response()->json(['res' => true, 'data' => $document], 201);
    }

    /**
     * Actualizar descripción y observaciones de un documento.
     */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'document_type' => ['sometimes', \Illuminate\Validation\Rule::in(\App\Enums\DocumentType::values())],
            'description'   => 'nullable|string|max:255',
            'observations'  => 'nullable|string|max:2000',
        ]);

        $document = StudentDocument::findOrFail($id);

        if (isset($data['document_type']) && $data['document_type'] !== 'OTRO') {
            $data['description'] = null;
        }

        $document->update($data);

        return response()->json(['res' => true, 'data' => $document->fresh()]);
    }

    /**
     * Eliminar documento.
     */
    public function destroy(int $id)
    {
        $document = StudentDocument::findOrFail($id);

        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }

        $document->delete();

        return response()->json(['res' => true, 'data' => null]);
    }
}
