<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePracticeVacantRequest;
use App\Http\Requests\SaveVacantPositionRequest;
use Illuminate\Http\Request;
use App\Models\Image;
use App\Models\BusinessData;
use App\Http\Requests\UpdateBusinessInformationRequest;
use App\Http\Requests\UpdatePracticeVacantRequest;
use App\Http\Requests\UpdateVacantPositionRequest;
use App\Models\VacantPosition;
use App\Models\BusinessVisualization;
use App\Models\Curriculum;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Log;
use App\Models\JobApplication;

class BusinessController extends Controller
{
    public function index()
    {
        $userId = auth()->id();
        $businessData = BusinessData::where('user_id', $userId)->first();
        $images = Image::where('user_id', $userId)->get();

        return response()->json([
            'images' => $images,
            'businessData' => $businessData
        ]);
    }

    public function getAllvacancies()
    {
        $userId = auth()->id();
        $result = VacantPosition::where('user_id', $userId)->get();

        return response()->json([
            'vacancies' => $result
        ]);
    }

    public function updateBusinessInformation(UpdateBusinessInformationRequest $request)
    {
        $business = BusinessData::find($request->id);
        $business->bs_name = $request->bs_name;
        $business->bs_director = $request->bs_director;
        $business->bs_rfc = $request->bs_rfc;
        $business->bs_country = $request->bs_country;
        $business->bs_state = $request->bs_state;
        $business->bs_locality = $request->bs_locality;
        $business->bs_adrress = $request->bs_adrress;
        $business->bs_telphone = $request->bs_telphone;
        $business->bs_line = $request->bs_line;
        $business->bs_description = $request->bs_description;
        $business->bs_website = $request->bs_website;
        $business->bs_other_line = $request->bs_other_line;

        $business->save();
        return response()->json([
            'res' => true,
            "msg" => "Actualización con  éxito",
            "updateBusiness" => $business
        ], 200);
    }

    public function storeVacantPosition(SaveVacantPositionRequest $request)
    {
        $user = auth()->user();
        $role = $user->roles->first();

        if (!$role) {
            return response()->json([
                'res' => false,
                'msg' => 'El usuario no tiene un rol asignado.'
            ], 403);
        }

        // Verificar límite de vacantes solo si no es PLATINUM o DIAMOND
        if (!in_array($role->name, ['PLATINUM', 'DIAMOND'])) {
            $currentVacancies = VacantPosition::where('user_id', $user->id)->count();

            if ($role->num_vacancies !== null && $currentVacancies >= $role->num_vacancies) {
                return response()->json([
                    'res' => false,
                    'msg' => 'Has alcanzado el límite máximo de vacantes permitido por tu plan.'
                ], 403);
            }
        }

        $vacant = new VacantPosition();
        $vacant->user_id = $user->id;
        $vacant->vacant_name = $request->vacant_name;
        $vacant->category = $request->category;
        $vacant->activities = $request->activities;
        $vacant->study_profile = $request->study_profile;
        $vacant->net_salary = $request->net_salary;
        $vacant->start_day = $request->start_day;
        $vacant->end_day = $request->end_day;
        $vacant->start_hour = $request->start_hour;
        $vacant->start_minute = $request->start_minute;
        $vacant->end_hour = $request->end_hour;
        $vacant->end_minute = $request->end_minute;
        $vacant->saturday_hour = $request->saturday_hour;
        $vacant->saturday_start_hour = $request->saturday_start_hour;
        $vacant->saturday_start_minute = $request->saturday_start_minute;
        $vacant->saturday_end_hour = $request->saturday_end_hour;
        $vacant->saturday_end_minute = $request->saturday_end_minute;
        $vacant->additional_time_info = $request->additional_time_info;
        $vacant->experience = $request->experience;
        $vacant->experience_description = $request->experience_description;
        $vacant->software_use = $request->software_use;
        $vacant->software_description = $request->software_description;
        $vacant->skills = $request->skills;
        $vacant->observations = $request->observations;
        $vacant->general_knowledge = $request->general_knowledge;
        $vacant->knowledge_description = $request->knowledge_description;
        $vacant->employment_contract = $request->employment_contract;
        $vacant->vacation = $request->vacation;
        $vacant->christmas_bonus = $request->christmas_bonus;
        $vacant->social_security = $request->social_security;
        $vacant->vacation_bonus = $request->vacation_bonus;
        $vacant->grocery_vouchers = $request->grocery_vouchers;
        $vacant->savings_fund = $request->savings_fund;
        $vacant->life_insurance = $request->life_insurance;
        $vacant->medical_expenses = $request->medical_expenses;
        $vacant->day_off = $request->day_off;
        $vacant->sunday_bonus = $request->sunday_bonus;
        $vacant->paternity_leave = $request->paternity_leave;
        $vacant->transportation_help = $request->transportation_help;
        $vacant->productivity_bonus = $request->productivity_bonus;
        $vacant->automobile = $request->automobile;
        $vacant->dining_room = $request->dining_room;
        $vacant->loans = $request->loans;
        $vacant->other = $request->other;
        $vacant->benefit_description = $request->benefit_description;
        $vacant->mode = $request->mode;

        $vacant->status = true;
        $vacant->campus = $user->campus;

        $vacant->save();

        return response()->json([
            'res' => true,
            'msg' => 'Información agregada con éxito',
            'createVacant' => $vacant
        ], 200);
    }

    public function storePracticeVacant(SavePracticeVacantRequest $request)
    {

        $user = auth()->user();
        $role = $user->roles->first();

        if (!$role) {
            return response()->json([
                'res' => false,
                'msg' => 'El usuario no tiene un rol asignado.'
            ], 403);
        }

        // Verificar límite de vacantes solo si no es PLATINUM o DIAMOND
        if (!in_array($role->name, ['PLATINUM', 'DIAMOND'])) {
            $currentVacancies = VacantPosition::where('user_id', $user->id)->count();

            if ($role->num_vacancies !== null && $currentVacancies >= $role->num_vacancies) {
                return response()->json([
                    'res' => false,
                    'msg' => 'Has alcanzado el límite máximo de vacantes permitido por tu plan.'
                ], 403);
            }
        }

        $vacant = new VacantPosition;
        $vacant->user_id = auth()->id();
        $vacant->vacant_name = $request->vacant_name;
        $vacant->category = $request->category;
        $vacant->activities = $request->activities;
        $vacant->study_profile = $request->study_profile;
        $vacant->financial_support = $request->financial_support;
        $vacant->support_amount = $request->support_amount;

        $vacant->start_day = $request->start_day;
        $vacant->end_day = $request->end_day;
        $vacant->start_hour = $request->start_hour;
        $vacant->start_minute = $request->start_minute;
        $vacant->end_hour = $request->end_hour;
        $vacant->end_minute = $request->end_minute;
        $vacant->semester = $request->semester;
        $vacant->software_use = $request->software_use;
        $vacant->software_description = $request->software_description;
        $vacant->skills = $request->skills;
        $vacant->general_knowledge = $request->general_knowledge;
        $vacant->knowledge_description = $request->knowledge_description;
        $vacant->mode = $request->mode;

        $vacant->status = true;

        $vacant->save();

        return response()->json([
            'res' => true,
            'msg' => 'Información agregada con éxito',
            "createPractice" => $vacant
        ], 200);
    }



    public function updateVacantPosition(UpdateVacantPositionRequest $request)
    {

        $vacant = VacantPosition::find($request->id);
        $vacant->vacant_name = $request->vacant_name;
        $vacant->activities = $request->activities;
        $vacant->study_profile = $request->study_profile;
        $vacant->financial_support = $request->financial_support;
        $vacant->net_salary = $request->net_salary;
        $vacant->support_amount = $request->support_amount;
        $vacant->start_day = $request->start_day;
        $vacant->end_day = $request->end_day;
        $vacant->start_hour = $request->start_hour;
        $vacant->start_minute = $request->start_minute;
        $vacant->end_hour = $request->end_hour;
        $vacant->end_minute = $request->end_minute;
        $vacant->saturday_hour = $request->saturday_hour;
        $vacant->saturday_start_hour = $request->saturday_start_hour;
        $vacant->saturday_start_minute = $request->saturday_start_minute;
        $vacant->saturday_end_hour = $request->saturday_end_hour;
        $vacant->saturday_end_minute = $request->saturday_end_minute;
        $vacant->additional_time_info = $request->additional_time_info;
        $vacant->experience = $request->experience;
        $vacant->experience_description = $request->experience_description;
        $vacant->software_use = $request->software_use;
        $vacant->software_description = $request->software_description;
        $vacant->skills = $request->skills;
        $vacant->observations = $request->observations;
        $vacant->semester = $request->semester;
        $vacant->general_knowledge = $request->general_knowledge;
        $vacant->knowledge_description = $request->knowledge_description;
        $vacant->employment_contract = $request->employment_contract;
        $vacant->vacation = $request->vacation;
        $vacant->christmas_bonus = $request->christmas_bonus;
        $vacant->social_security = $request->social_security;
        $vacant->vacation_bonus = $request->vacation_bonus;
        $vacant->grocery_vouchers = $request->grocery_vouchers;
        $vacant->savings_fund = $request->savings_fund;
        $vacant->life_insurance = $request->life_insurance;
        $vacant->medical_expenses = $request->medical_expenses;
        $vacant->day_off = $request->day_off;
        $vacant->sunday_bonus = $request->sunday_bonus;
        $vacant->paternity_leave = $request->paternity_leave;
        $vacant->transportation_help = $request->transportation_help;
        $vacant->productivity_bonus = $request->productivity_bonus;
        $vacant->automobile = $request->automobile;
        $vacant->dining_room = $request->dining_room;
        $vacant->loans = $request->loans;
        $vacant->other = $request->other;
        $vacant->benefit_description = $request->benefit_description;
        // $vacant->status = true;
        $vacant->mode = $request->mode;

        $vacant->save();

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updateVacant" => $vacant
        ], 200);
    }

    public function updatePracticeVacant(UpdatePracticeVacantRequest $request)
    {

        $vacant = VacantPosition::find($request->id);
        $vacant->vacant_name = $request->vacant_name;
        $vacant->activities = $request->activities;
        $vacant->study_profile = $request->study_profile;
        $vacant->financial_support = $request->financial_support;
        $vacant->support_amount = $request->support_amount;

        $vacant->start_day = $request->start_day;
        $vacant->end_day = $request->end_day;
        $vacant->start_hour = $request->start_hour;
        $vacant->start_minute = $request->start_minute;
        $vacant->end_hour = $request->end_hour;
        $vacant->end_minute = $request->end_minute;
        $vacant->semester = $request->semester;
        $vacant->software_use = $request->software_use;
        $vacant->software_description = $request->software_description;
        $vacant->skills = $request->skills;
        $vacant->general_knowledge = $request->general_knowledge;
        $vacant->knowledge_description = $request->knowledge_description;
        $vacant->mode = $request->mode;

        $vacant->save();

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updatePractice" => $vacant
        ], 200);
    }

    public function destroyVacantPosition($id)
    {
        $vacant = VacantPosition::find($id);
        if ($vacant) {
            JobApplication::where('vacant_id', $id)->delete();
            $vacant->delete();
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


    public function updateVacantPositionStatus(Request $request, $id)
    {
        $vacant = VacantPosition::find($id);

        if (!$vacant) {
            return response()->json([
                'res' => false,
                'msg' => 'La vacante no ha sido encontrada.',
            ], 404);
        }

        // Validar el campo candidate_type solo si la vacante está siendo desactivada
        $validatedData = [];
        if ($vacant->status) { // Si está activa y va a desactivarse
            $validatedData = $request->validate([
                'candidate_type' => 'required|in:INTERNAL,EXTERNAL,NOT_COVERED',
            ]);
            $vacant->candidate_type = $validatedData['candidate_type'];
        } else { // Si estaba inactiva y va a activarse
            $vacant->candidate_type = null; // O "NOT_COVERED"
        }

        // Cambiar el estado de la vacante
        $vacant->status = !$vacant->status;
        $vacant->save();

        return response()->json([
            'res' => true,
            'msg' => 'Estado actualizado con éxito.',
            'vacant' => $vacant,
        ], 200);
    }
}