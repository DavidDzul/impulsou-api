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

    public function area()
    {
        return $this->belongsTo(Area::class);
    }
}