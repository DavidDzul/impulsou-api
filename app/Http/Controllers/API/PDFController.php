<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Exception; // Asegúrate de importar Exception si usas namespaces
use Illuminate\Support\Facades\Log;
use App\Models\Curriculum;
use App\Models\Image;
use App\Models\WorkExperience;
use App\Models\AcademicInformation;
use App\Models\ContinuingEducation;
use App\Models\TechnicalKnowledge;

class PDFController extends Controller
{
    public function generatePDF($id)
    {
        try {
            // Asegúrate de que se envía este parámetro

            $curriculum = Curriculum::where('user_id', $id)->first();
            $photo = Image::where('user_id', $id)->first();
            $workExperiences = WorkExperience::where('user_id', $id)->get();
            $academic = AcademicInformation::where('user_id', $id)->get();
            $education = ContinuingEducation::where('user_id', $id)->get();
            $skills = TechnicalKnowledge::where('user_id', $id)->get();

            // Generar el PDF
            $pdf = Pdf::loadView('pdf.template', [
                'photo' => $photo,
                'curriculum' => $curriculum,
                'education' => $education,
                'academic' => $academic,
                'workExperiences' => $workExperiences,
                'skills' => $skills,
            ]);

            // Retornar el PDF como descarga
            return $pdf->download("User_{$id}_Curriculum.pdf");
        } catch (Exception $e) {
            // Registrar el error
            Log::error('Error al generar el PDF: ' . $e->getMessage());

            // Retornar un error 500
            return response()->json([
                'error' => 'Hubo un problema al generar el PDF.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}