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

        $version = StudentDocument::where('user_id', $validated['user_id'])
            ->where('document_type', $validated['document_type'])
            ->where('period_year', $validated['period_year'])
            ->where('period_month', $validated['period_month'])
            ->max('version') ?? 0;

        $document = StudentDocument::create([
            'user_id'       => $validated['user_id'],
            'document_type' => $validated['document_type'],
            'period_year'   => $validated['period_year'],
            'period_month'  => $validated['period_month'],
            'status'        => DocumentStatus::SUBMITTED->value,
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'file_size'     => $file->getSize(),
            'version'       => $version + 1,
        ]);

        return response()->json(['res' => true, 'data' => $document], 201);
    }

    /**
     * Aceptar documento.
     */
    public function accept(int $id)
    {
        $document = StudentDocument::findOrFail($id);
        $document->update(['status' => DocumentStatus::ACCEPTED->value, 'rejected_reason' => null]);

        return response()->json(['res' => true, 'data' => $document->fresh()]);
    }

    /**
     * Rechazar documento con motivo.
     */
    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        $document = StudentDocument::findOrFail($id);
        $document->update([
            'status'          => DocumentStatus::REJECTED->value,
            'rejected_reason' => $data['reason'],
        ]);

        return response()->json(['res' => true, 'data' => $document->fresh()]);
    }

    /**
     * Eliminar documento (solo si está en PENDING o REJECTED).
     */
    public function destroy(int $id)
    {
        $document = StudentDocument::findOrFail($id);

        if (!in_array($document->status, [DocumentStatus::PENDING, DocumentStatus::REJECTED])) {
            return response()->json(['res' => false, 'msg' => 'No se puede eliminar un documento aceptado.'], 422);
        }

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        return response()->json(['res' => true, 'data' => null]);
    }
}
