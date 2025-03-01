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
    public function validateAndGeneratePDF($id)
    {
        try {
            $authUser = auth()->user();
            $role = $authUser->roles->first();

            // Verificar si el CV existe
            $cvExists = Curriculum::find($id);
            if (!$cvExists) {
                return response()->json([
                    'res' => false,
                    'msg' => 'El CV no existe.',
                ], 404);
            }

            // Obtener el user_id del propietario del CV
            $cvOwnerId = $cvExists->user_id;

            // Verificar si el usuario tiene un rol asignado
            if (!$role) {
                return response()->json([
                    'res' => false,
                    'msg' => 'No tienes un rol asignado para realizar esta acción.',
                ], 403);
            }

            // Validar límite de visualizaciones solo si no es PLATINUM o DIAMOND
            if (!$role->unlimited) {
                $currentVisualizations = BusinessVisualization::where('user_id', $authUser->id)->count();

                if ($currentVisualizations >= $role->num_visualizations) {
                    return response()->json([
                        'res' => false,
                        'msg' => 'Has alcanzado el límite máximo de visualizaciones permitido por tu rol.',
                    ], 403);
                }
            }

            // Registrar la visualización
            BusinessVisualization::create([
                'user_id' => $authUser->id,
                'cv_id' => $id,
            ]);

            // Si pasa la validación, generar el PDF
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