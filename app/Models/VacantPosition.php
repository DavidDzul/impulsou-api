<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VacantPosition extends Model
{
    use HasFactory;
    protected $table = 'vacant_position';

    protected $fillable = [
        'user_id',
        'vacant_name',
        'mode',
        'category',
        'activities',
        'study_profile',
        'financial_support',
        'net_salary',
        'support_amount',
        'start_day',
        'end_day',
        'start_hour',
        'start_minute',
        'end_hour',
        'end_minute',
        'saturday_hour',
        'saturday_start_hour',
        'saturday_start_minute',
        'saturday_end_hour',
        'saturday_end_minute',
        'additional_time_info',
        'experience',
        'experience_description',
        'software_use',
        'software_description',
        'skills',
        'observations',
        'semester',
        'general_knowledge',
        'knowledge_description',

        'utilities',
        'bonuses',
        'dining_room',
        'savings_fund',
        'grocery_vouchers',
        'extensive_vacation_bonus',
        'top_christmas_bonus',
        'flexible_hours',
        'major_medical_expenses',
        'transportation_help',
        'automobile',
        'loans',
        'life_insurance',
        'other',
        'benefit_description',
        'campus',
        'status',

        'candidate_type',
        'compensations'
    ];

    public static function createVacantRules()
    {
        return [
            "user_id" => "required|exists:users,id",
            "vacant_name" => "required|string|max:255",
            "category" => "required|string|max:255",
            "activities" => "required|string|max:500",
            "study_profile" => "required|string|max:255",
            "net_salary" => "required|string|max:255",
            "start_day" => "required|string|max:255",
            "end_day" => "required|string|max:255",
            "start_hour" => "required|string|max:255",
            "start_minute" => "required|string|max:255",
            "end_hour" => "required|string|max:255",
            "end_minute" => "required|string|max:255",
            "saturday_hour" => "nullable|boolean",
            "saturday_start_hour" => "nullable|string|max:255",
            "saturday_start_minute" => "nullable|string|max:255",
            "saturday_end_hour" => "nullable|string|max:255",
            "saturday_end_minute" => "nullable|string|max:255",
            "additional_time_info" => "nullable|string|max:255",
            "experience" => "nullable|boolean",
            "experience_description" => "nullable|string|max:255",
            "software_use" => "nullable|boolean",
            "software_description" => "nullable|string|max:255",
            "skills" => "required|string|max:255",
            "observations" => "nullable|string|max:500",
            "general_knowledge" => "nnullable|boolean",
            "knowledge_description" => "nullable|string|max:500",

            "utilities" => "nullable|boolean",
            "bonuses" => "nullable|boolean",
            "dining_room" => "nullable|boolean",
            "savings_fund" => "nullable|boolean",
            "grocery_vouchers" => "nullable|boolean",
            "extensive_vacation_bonus" => "nullable|boolean",
            "top_christmas_bonus" => "nullable|boolean",
            "flexible_hours" => "nullable|boolean",
            "major_medical_expenses" => "nullable|boolean",
            "transportation_help" => "nullable|boolean",
            "automobile" => "nullable|boolean",
            "loans" => "nullable|boolean",
            "life_insurance" => "nullable|boolean",
            "other" => "nullable|boolean",
            "benefit_description" => "nullable|string|max:500",

            "mode" => "required|string|max:255",
            'campus' => 'required|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
        ];
    }

    public static function updateVacantRules()
    {
        return [
            "user_id" => "sometimes|exists:users,id",
            "vacant_name" => "sometimes|string|max:255",
            "category" => "sometimes|string|max:255",
            "activities" => "sometimes|string|max:500",
            "study_profile" => "sometimes|string|max:255",
            "net_salary" => "sometimes|string|max:255",
            "start_day" => "sometimes|string|max:255",
            "end_day" => "sometimes|string|max:255",
            "start_hour" => "sometimes|string|max:255",
            "start_minute" => "sometimes|string|max:255",
            "end_hour" => "sometimes|string|max:255",
            "end_minute" => "sometimes|string|max:255",
            "saturday_hour" => "nullable|boolean",
            "saturday_start_hour" => "nullable|string|max:255",
            "saturday_start_minute" => "nullable|string|max:255",
            "saturday_end_hour" => "nullable|string|max:255",
            "saturday_end_minute" => "nullable|string|max:255",
            "additional_time_info" => "nullable|string|max:255",
            "experience" => "nullable|boolean",
            "experience_description" => "nullable|string|max:255",
            "software_use" => "nullable|boolean",
            "software_description" => "nullable|string|max:255",
            "skills" => "sometimes|string|max:255",
            "observations" => "nullable|string|max:500",
            "general_knowledge" => "nnullable|boolean",
            "knowledge_description" => "nullable|string|max:500",

            "utilities" => "nullable|boolean",
            "bonuses" => "nullable|boolean",
            "dining_room" => "nullable|boolean",
            "savings_fund" => "nullable|boolean",
            "grocery_vouchers" => "nullable|boolean",
            "extensive_vacation_bonus" => "nullable|boolean",
            "top_christmas_bonus" => "nullable|boolean",
            "flexible_hours" => "nullable|boolean",
            "major_medical_expenses" => "nullable|boolean",
            "transportation_help" => "nullable|boolean",
            "automobile" => "nullable|boolean",
            "loans" => "nullable|boolean",
            "life_insurance" => "nullable|boolean",
            "other" => "nullable|boolean",
            "benefit_description" => "nullable|string|max:500",
        ];
    }

    public static function createPracticeRules()
    {
        return [
            "user_id" => "required|exists:users,id",
            "vacant_name" => "required|string|max:255",
            "category" => "required|string|max:255",
            "activities" => "required|string|max:500",
            "study_profile" => "required|string|max:255",
            "financial_support" => "nullable|boolean",
            "support_amount" => "nullable|string|max:255",
            "start_day" => "required|string|max:255",
            "end_day" => "required|string|max:255",
            "start_hour" => "required|string|max:255",
            "start_minute" => "required|string|max:255",
            "end_hour" => "required|string|max:255",
            "end_minute" => "required|string|max:255",
            "saturday_hour" => "nullable|boolean",
            "semester" => "required|string|max:255",
            "software_use" => "nullable|boolean",
            "software_description" => "nullable|string|max:255",
            "skills" => "required|string|max:255",
            "general_knowledge" => "nullable|boolean",
            "knowledge_description" => "nullable|string|max:500",
            "observations" => "nullable|string|max:500",

            "mode" => "required|string|max:255",
            'campus' => 'required|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
        ];
    }

    public static function updatePracticeRules()
    {
        return [
            "user_id" => "sometimes|exists:users,id",
            "vacant_name" => "sometimes|string|max:255",
            "category" => "sometimes|string|max:255",
            "activities" => "sometimes|string|max:500",
            "study_profile" => "sometimes|string|max:255",
            "financial_support" => "nullable|boolean",
            "support_amount" => "nullable|string|max:255",
            "start_day" => "sometimes|string|max:255",
            "end_day" => "sometimes|string|max:255",
            "start_hour" => "sometimes|string|max:255",
            "start_minute" => "sometimes|string|max:255",
            "end_hour" => "sometimes|string|max:255",
            "end_minute" => "sometimes|string|max:255",
            "saturday_hour" => "nullable|boolean",
            "semester" => "sometimes|string|max:255",
            "software_use" => "nullable|boolean",
            "software_description" => "nullable|string|max:255",
            "skills" => "sometimes|string|max:255",
            "general_knowledge" => "nullable|boolean",
            "knowledge_description" => "nullable|string|max:500",
            "observations" => "nullable|string|max:500",

        ];
    }

    public static function createJrRules()
    {
        return [
            "user_id" => "required|exists:users,id",
            "vacant_name" => "required|string|max:255",
            "category" => "required|string|max:255",
            "activities" => "required|string|max:500",
            "study_profile" => "required|string|max:255",
            "net_salary" => "required|string|max:255",

            "compensations" => "nullable|string|max:500",

            "start_day" => "required|string|max:255",
            "end_day" => "required|string|max:255",
            "start_hour" => "required|string|max:255",
            "start_minute" => "required|string|max:255",
            "end_hour" => "required|string|max:255",
            "end_minute" => "required|string|max:255",
            "saturday_hour" => "nullable|boolean",
            "semester" => "required|string|max:255",
            "software_use" => "nullable|boolean",
            "software_description" => "nullable|string|max:255",
            "skills" => "required|string|max:255",
            "general_knowledge" => "nullable|boolean",
            "knowledge_description" => "nullable|string|max:500",
            "observations" => "nullable|string|max:500",

            "mode" => "required|string|max:255",
            'campus' => 'required|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
        ];
    }

    public static function updateJrRules()
    {
        return [
            "user_id" => "sometimes|exists:users,id",
            "vacant_name" => "sometimes|string|max:255",
            "category" => "sometimes|string|max:255",
            "activities" => "sometimes|string|max:500",
            "study_profile" => "sometimes|string|max:255",
            "net_salary" => "sometimes|string|max:255",

            "compensations" => "nullable|string|max:500",

            "start_day" => "sometimes|string|max:255",
            "end_day" => "sometimes|string|max:255",
            "start_hour" => "sometimes|string|max:255",
            "start_minute" => "sometimes|string|max:255",
            "end_hour" => "sometimes|string|max:255",
            "end_minute" => "sometimes|string|max:255",
            "saturday_hour" => "nullable|boolean",
            "semester" => "sometimes|string|max:255",
            "software_use" => "nullable|boolean",
            "software_description" => "nullable|string|max:255",
            "skills" => "sometimes|string|max:255",
            "general_knowledge" => "nullable|boolean",
            "knowledge_description" => "nullable|string|max:500",
            "observations" => "nullable|string|max:500",

            "mode" => "sometimes|string|max:255",
            'campus' => 'sometimes|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
        ];
    }

    public static function updateStatusRules()
    {
        return [
            'candidate_type' => 'required|in:INTERNAL,EXTERNAL,NOT_COVERED'
        ];
    }

    public function image()
    {
        return $this->hasOne(Image::class, 'user_id', 'user_id');
    }

    public function business()
    {
        return $this->hasOne(BusinessData::class, 'user_id', 'user_id');
    }
}