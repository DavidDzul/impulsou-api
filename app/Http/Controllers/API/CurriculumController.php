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
        $curriculum = new Curriculum;
        $curriculum->user_id = auth()->id();
        $curriculum->first_name = $request->first_name;
        $curriculum->last_name = $request->last_name;
        $curriculum->email = $request->email;
        $curriculum->day_birth = $request->day_birth;
        $curriculum->month_birth = $request->month_birth;
        $curriculum->year_birth = $request->year_birth;
        $curriculum->phone_num = $request->phone_num;
        $curriculum->country = $request->country;
        $curriculum->state = $request->state;
        $curriculum->locality = $request->locality;
        $curriculum->professional_title = $request->professional_title;
        $curriculum->professional_summary = $request->professional_summary;
        $curriculum->linkedin = $request->linkedin;
        $curriculum->skill_1 = $request->skill_1;
        $curriculum->skill_2 = $request->skill_2;
        $curriculum->skill_3 = $request->skill_3;
        $curriculum->skill_4 = $request->skill_4;
        $curriculum->skill_5 = $request->skill_5;

        $curriculum->save();

        return response()->json([
            'res' => true,
            'msg' => 'Información personal agregada con éxito',
            "curriculum" => $curriculum
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
}
