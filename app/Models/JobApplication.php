<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_id',
        'vacant_id',
        'rejected_by',
        'rejected_reason',
        'rejected_other'
    ];

    public static function createRules()
    {
        return [
            'user_id' => 'required|exists:users,id',
            'business_id' => 'required|exists:users,id',
            'vacant_id' => 'required|exists:vacant_position,id',
        ];
    }

    public static function rejectedRules()
    {
        return [
            'status' => 'required|in:PENDING,ACCEPTED,REJECTED',
            'rejected_reason' => 'required_if:status,REJECTED|in:BS_UNSOLICITED,BS_WAS_COVERED,BS_NOT_REQUIRED,BS_USER_NOT_CONTINUE,US_FIND_JOB,US_NOT_EXPECTATIONS,US_PERSONAL_PROBLEMS,US_CONFUSION,OTHER',
            // 'rejected_other' => 'nullable|string|max:255',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function business()
    {
        return $this->belongsTo(BusinessData::class, 'business_id',);
    }

    public function vacant()
    {
        return $this->belongsTo(VacantPosition::class, 'vacant_id',);
    }
}