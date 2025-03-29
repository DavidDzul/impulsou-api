<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateData extends Model
{
    use HasFactory;

    protected $table = 'candidate_data';

    protected $fillable = [
        'user_type',
        'campus',
        'job_type',
        'area_id',
        'count',
    ];


    public static function createRules()
    {
        return [
            'user_type' => 'required|string|in:BEC_ACTIVE,BEC_INACTIVE',
            'campus' => 'required|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
            'job_type' => 'required|string|in:PROFESSIONAL_PRACTICE,PART_TIME,FULL_TIME',
            'area_id' => 'required|exists:areas,id',
            'count' => 'required|integer',
        ];
    }

    public static function updateRules()
    {
        return [
            'user_type' => 'sometimes|string|in:BEC_ACTIVE,BEC_INACTIVE',
            'campus' => 'sometimes|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
            'job_type' => 'sometimes|string|in:PROFESSIONAL_PRACTICE,PART_TIME,FULL_TIME',
            'area_id' => 'sometimes|exists:areas,id',
            'count' => 'sometimes|integer',
        ];
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}