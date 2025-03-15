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
use App\Models\BusinessVisualization;

class PDFController extends Controller
{
    public function validateAndGeneratePDF(Request $request, $id)
    {
        try {
            $user = $request->user();
            $role = $user->roles()->with('configuration')->first();

            $cvExists = Curriculum::find($id);
            if (!$cvExists) {
                return response()->json([
                    'res' => false,
                    'msg' => 'El CV no existe.',
                ], 404);
            }

            $cvOwnerId = $cvExists->user_id;

            if (!$role && !$role->configuration) {
                return response()->json([
                    'res' => false,
                    'msg' => 'Error con el rol asignado. Contacta a soporte para solucionar el problema.'
                ], 403);
            }

            $roleConfig = $role->configuration;

            if (!$roleConfig->unlimited) {
                $currentVisualizations = BusinessVisualization::where('user_id', $user->id)->count();

                if ($currentVisualizations >= $roleConfig->num_visualizations) {
                    return response()->json([
                        'res' => false,
                        'msg' => 'Has alcanzado el límite máximo de visualizaciones permitido por tu rol.',
                    ], 403);
                }
            }

            BusinessVisualization::create([
                'user_id' => $user->id,
                'cv_id' => $id,
            ]);

            return $this->generatePDF($cvOwnerId);
        } catch (Exception $e) {
            Log::error('Error al validar visualizaciones o generar el PDF: ' . $e->getMessage());

            return response()->json([
                'error' => 'Hubo un problema al validar las visualizaciones.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function generatePDF($id)
    {
        try {

            $curriculum = Curriculum::where('user_id', $id)->first();
            $photo = Image::where('user_id', $id)->first();
            $workExperiences = WorkExperience::where('user_id', $id)->get();
            $academic = AcademicInformation::where('user_id', $id)->get();
            $education = ContinuingEducation::where('user_id', $id)->get();
            $skills = TechnicalKnowledge::where('user_id', $id)->get();

            $pdf = Pdf::loadView('pdf.template', [
                'photo' => $photo,
                'curriculum' => $curriculum,
                'education' => $education,
                'academic' => $academic,
                'workExperiences' => $workExperiences,
                'skills' => $skills,
            ]);

            return $pdf->download("User_{$id}_Curriculum.pdf");
        } catch (Exception $e) {
            Log::error('Error al generar el PDF: ' . $e->getMessage());
            return response()->json([
                'error' => 'Hubo un problema al generar el PDF.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
