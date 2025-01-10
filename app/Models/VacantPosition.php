<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VacantPosition extends Model
{
    use HasFactory;
    protected $table = 'vacant_position';

    protected $fillable = [
        'vacant_name',
        'category',
        'activities',
        'study_profile',
        'net_salary',
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
        'general_knowledge',
        'knowledge_description',
        'employment_contract',
        'vacation',
        'christmas_bonus',
        'social_security',
        'vacation_bonus',
        'grocery_vouchers',
        'savings_fund',
        'life_insurance',
        'medical_expenses',
        'day_off',
        'sunday_bonus',
        'paternity_leave',
        'transportation_help',
        'productivity_bonus',
        'automobile',
        'dining_room',
        'loans',
        'other',
        'benefit_description',

    ];

    public function image()
    {
        return $this->hasOne(Image::class, 'user_id', 'user_id');
    }

    public function business()
    {
        return $this->hasOne(BusinessData::class, 'user_id', 'user_id');
    }
}