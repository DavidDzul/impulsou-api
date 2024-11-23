<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class PDFController extends Controller
{
    public function generatePDF()
    {
        $user = User::with(['images', 'curriculums', 'workExperiences'])->findOrFail(2);

        $curriculum = $user->curriculums;
        $photo = $user->images;

        $education = [
            ['degree' => 'Licenciatura en Sistemas', 'institution' => 'Universidad X', 'start_year' => 2015, 'end_year' => 2019],
            ['degree' => 'Maestría en TI', 'institution' => 'Universidad Y', 'start_year' => 2020, 'end_year' => 2022],
        ];

        $workExperiences = $user->workExperiences->map(function ($experience) {
            return [
                'job_title' => $experience->job_position,
                'company' => $experience->business_name,
                'start_date' => $experience->start_date,
                'end_date' => $experience->end_date,
                'description' => $experience->responsibility,
            ];
        });

        $skills = ['Laravel', 'Vue.js', 'JavaScript', 'PHP', 'Git'];

        // Generar el PDF con los datos
        $pdf = Pdf::loadView('pdf.template', [
            'photo' => $photo,
            'curriculum' => $curriculum,
            'education' => $education,
            'workExperiences' => $workExperiences,
            'skills' => $skills,
        ]);

        // Retornar el PDF como descarga
        return $pdf->download('User_Curriculum.pdf');
    }
}
