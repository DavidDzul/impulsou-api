<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCurriculumRequest;
use Illuminate\Http\Request;
use App\Models\Image;
use App\Models\Curriculum;
use App\Models\WorkExperience;
use App\Http\Requests\SaveWorkExperienceRequest;
use App\Http\Requests\UpdateWorkExperienceRequest;
use App\Models\AcademicInformation;
use App\Models\ContinuingEducation;
use App\Models\TechnicalKnowledge;
use App\Http\Requests\SaveAcademicInformationRequest;
use App\Http\Requests\UpdateAcademicInformationRequest;
use App\Http\Requests\SaveContinuingEducationRequest;
use App\Http\Requests\SaveTechnicalKnowledgeRequest;
use App\Http\Requests\UpdateContinuingEducationRequest;
use App\Http\Requests\UpdateTechnicalKnowledgeRequest;

class CurriculumController extends Controller
{
    public function index()
    {
        $userId = auth()->id();
        $curriculum = Curriculum::where('user_id', $userId)->first();
        $images = Image::where('user_id', $userId)->get();
        $workExperience = WorkExperience::where('user_id', $userId)->get();
        $academic = AcademicInformation::where('user_id', $userId)->get();
        $education = ContinuingEducation::where('user_id', $userId)->get();
        $knowledge = TechnicalKnowledge::where('user_id', $userId)->get();

        return response()->json([
            'info' => $curriculum,
            'images' => $images,
            'workExperience' => $workExperience,
            'academic' => $academic,
            'education' => $education,
            'knowledge' => $knowledge,
        ]);
    }

    public function store(SaveCurriculumRequest $request)
    {
        $curriculum = Curriculum::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'day_birth' => $request->day_birth,
                'month_birth' => $request->month_birth,
                'year_birth' => $request->year_birth,
                'phone_num' => $request->phone_num,
                'country' => $request->country,
                'state' => $request->state,
                'locality' => $request->locality,
                'professional_title' => $request->professional_title,
                'professional_summary' => $request->professional_summary,
                'linkedin' => $request->linkedin,
                'skill_1' => $request->skill_1,
                'skill_2' => $request->skill_2,
                'skill_3' => $request->skill_3,
                'skill_4' => $request->skill_4,
                'skill_5' => $request->skill_5,
                'public' => $request->public || 0,
            ]
        );

        return response()->json([
            'res' => true,
            'msg' => $curriculum->wasRecentlyCreated
                ? 'Información personal creada con éxito'
                : 'Información personal actualizada con éxito',
            'curriculum' => $curriculum,
        ], 200);
    }

    public function storeWorkExperience(SaveWorkExperienceRequest $request)
    {

        $work = new WorkExperience;
        $work->user_id = auth()->id();
        $work->job_position = $request->job_position;
        $work->business_name = $request->business_name;
        $work->start_date = $request->start_date;
        $work->end_date = $request->end_date;
        $work->responsibility = $request->responsibility;
        $work->achievement = $request->achievement;

        $work->save();

        return response()->json([
            'res' => true,
            'msg' => 'Información agregada con éxito',
            "createWork" => $work
        ], 200);
    }

    public function updateWorkExperience(UpdateWorkExperienceRequest $request)
    {
        $work = WorkExperience::find($request->id);
        $work->job_position = $request->job_position;
        $work->business_name = $request->business_name;
        $work->start_date = $request->start_date;
        $work->end_date = $request->end_date;
        $work->responsibility = $request->responsibility;
        $work->achievement = $request->achievement;

        $work->save();
        return response()->json([
            'res' => true,
            "msg" => "Actualización con  éxito",
            "updateWork" => $work
        ], 200);
    }

    public function destroyWorkExperience($id)
    {
        $work = WorkExperience::find($id);
        if ($work) {
            $work->delete();
            return response()->json([
                'res' => true,
                "msg" => "Eliminado con  éxito",
            ], 200);
        } else {
            return response()->json([
                'res' => false,
                "msg" => "Error al eliminar",
            ], 404);
        }
    }

    public function storeAcademicInformation(SaveAcademicInformationRequest $request)
    {

        $academic = new AcademicInformation();
        $academic->user_id = auth()->id();
        $academic->postgraduate_name = $request->postgraduate_name;
        $academic->institute_name = $request->institute_name;
        $academic->postgraduate_start_date = $request->postgraduate_start_date;
        $academic->postgraduate_end_date = $request->postgraduate_end_date;
        $academic->highlight = $request->highlight;

        $academic->save();

        return response()->json([
            'res' => true,
            'msg' => 'Información agregada con éxito',
            "createAcademic" => $academic
        ], 200);
    }

    public function updateAcademicInformation(UpdateAcademicInformationRequest $request)
    {
        $academic = AcademicInformation::find($request->id);
        $academic->postgraduate_name = $request->postgraduate_name;
        $academic->institute_name = $request->institute_name;
        $academic->postgraduate_start_date = $request->postgraduate_start_date;
        $academic->postgraduate_end_date = $request->postgraduate_end_date;
        $academic->highlight = $request->highlight;

        $academic->save();
        return response()->json([
            'res' => true,
            "msg" => "Actualización con  éxito",
            "updateAcademic" => $academic
        ], 200);
    }

    public function destroyAcademicInformatio($id)
    {
        $academic = AcademicInformation::find($id);
        if ($academic) {
            $academic->delete();
            return response()->json([
                'res' => true,
                "msg" => "Eliminado con  éxito",
            ], 200);
        } else {
            return response()->json([
                'res' => false,
                "msg" => "Error al eliminar",
            ], 404);
        }
    }

    public function storeContinuingEducation(SaveContinuingEducationRequest $request)
    {

        $education = new ContinuingEducation();
        $education->user_id = auth()->id();
        $education->course_name = $request->course_name;
        $education->course_institute = $request->course_institute;
        $education->course_start_date = $request->course_start_date;
        $education->course_end_date = $request->course_end_date;

        $education->save();

        return response()->json([
            'res' => true,
            'msg' => 'Información agregada con éxito',
            "createEducation" => $education
        ], 200);
    }


    public function updateContinuingEducation(UpdateContinuingEducationRequest $request)
    {
        $education = ContinuingEducation::find($request->id);
        $education->course_name = $request->course_name;
        $education->course_institute = $request->course_institute;
        $education->course_start_date = $request->course_start_date;
        $education->course_end_date = $request->course_end_date;

        $education->save();
        return response()->json([
            'res' => true,
            "msg" => "Actualización con  éxito",
            "updateEducation" => $education
        ], 200);
    }

    public function destroyContinuingEducation($id)
    {
        $education = ContinuingEducation::find($id);
        if ($education) {
            $education->delete();
            return response()->json([
                'res' => true,
                "msg" => "Eliminado con  éxito",
            ], 200);
        } else {
            return response()->json([
                'res' => false,
                "msg" => "Error al eliminar",
            ], 404);
        }
    }

    public function storeTechnicalKnowledge(SaveTechnicalKnowledgeRequest $request)
    {

        $knowledge = new TechnicalKnowledge();
        $knowledge->user_id = auth()->id();
        $knowledge->type = $request->type;
        $knowledge->other_knowledge = $request->other_knowledge;
        $knowledge->description_knowledge = $request->description_knowledge;
        $knowledge->level = $request->level;

        $knowledge->save();

        return response()->json([
            'res' => true,
            'msg' => 'Información agregada con éxito',
            "createKnowledge" => $knowledge
        ], 200);
    }

    public function updateTechnicalKnowledge(UpdateTechnicalKnowledgeRequest $request)
    {
        $knowledge = TechnicalKnowledge::find($request->id);
        $knowledge->type = $request->type;
        $knowledge->other_knowledge = $request->other_knowledge;
        $knowledge->description_knowledge = $request->description_knowledge;
        $knowledge->level = $request->level;

        $knowledge->save();
        return response()->json([
            'res' => true,
            "msg" => "Actualización con  éxito",
            "updateKnowledge" => $knowledge
        ], 200);
    }

    public function destroyTechnicalKnowledge($id)
    {
        $knowledge = TechnicalKnowledge::find($id);
        if ($knowledge) {
            $knowledge->delete();
            return response()->json([
                'res' => true,
                "msg" => "Eliminado con  éxito",
            ], 200);
        } else {
            return response()->json([
                'res' => false,
                "msg" => "Error al eliminar",
            ], 404);
        }
    }

    public function updateCurriculumStatus()
    {
        $curriculum = Curriculum::where('user_id', auth()->id())->first();

        if (!$curriculum) {
            return response()->json([
                'res' => false,
                'msg' => 'Curriculum no encontrado para el usuario autenticado.'
            ], 404);
        }

        $curriculum->public = !$curriculum->public;
        $curriculum->save();

        return response()->json([
            'res' => true,
            'msg' => 'Estado actualizado con éxito',
            'curriculum' => $curriculum
        ], 200);
    }
}