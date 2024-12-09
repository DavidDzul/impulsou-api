<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveVacantPositionRequest;
use Illuminate\Http\Request;
use App\Models\Image;
use App\Models\BusinessData;
use App\Http\Requests\UpdateBusinessInformationRequest;
use App\Http\Requests\UpdateVacantPositionRequest;
use App\Models\VacantPosition;

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

        $vacant = new VacantPosition;
        $vacant->user_id = auth()->id();
        $vacant->vacant_name = $request->vacant_name;
        $vacant->category = $request->category;
        $vacant->activities = $request->activities;
        $vacant->study_profile = $request->study_profile;
        $vacant->financial_support = $request->financial_support;
        $vacant->net_salary = $request->net_salary;
        $vacant->support_amount = $request->support_amount;
        $vacant->start_day = $request->start_day;
        $vacant->end_day = $request->end_day;
        $vacant->start_hour = $request->start_hour;
        $vacant->end_hour = $request->end_hour;
        $vacant->saturday_hour = $request->saturday_hour;
        $vacant->saturday_start_day = $request->saturday_start_day;
        $vacant->saturday_end_day = $request->saturday_end_day;
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
        $vacant->contact_name = $request->contact_name;
        $vacant->contact_position = $request->contact_position;
        $vacant->contact_telphone = $request->contact_telphone;
        $vacant->contact_email = $request->contact_email;
        $vacant->status = true;

        $vacant->save();

        return response()->json([
            'res' => true,
            'msg' => 'Información agregada con éxito',
            "createVacant" => $vacant
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
        $vacant->end_hour = $request->end_hour;
        $vacant->saturday_hour = $request->saturday_hour;
        $vacant->saturday_start_day = $request->saturday_start_day;
        $vacant->saturday_end_day = $request->saturday_end_day;
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
        $vacant->contact_name = $request->contact_name;
        $vacant->contact_position = $request->contact_position;
        $vacant->contact_telphone = $request->contact_telphone;
        $vacant->contact_email = $request->contact_email;
        $vacant->status = true;

        $vacant->save();

        return response()->json([
            'res' => true,
            'msg' => 'Actualización con éxito',
            "updateVacant" => $vacant
        ], 200);
    }

    public function destroyVacantPosition($id)
    {
        $vacant = VacantPosition::find($id);
        if ($vacant) {
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

    public function updateVacantPositionStatus($id)
    {
        $vacant = VacantPosition::where('id', $id)->first();

        if (!$vacant) {
            return response()->json([
                'res' => false,
                'msg' => 'La vacante no ha sido encontrada.'
            ], 404);
        }

        $vacant->status = !$vacant->status;
        $vacant->save();

        return response()->json([
            'res' => true,
            'msg' => 'Estado actualizado con éxito',
            'vacant' => $vacant
        ], 200);
    }
}
