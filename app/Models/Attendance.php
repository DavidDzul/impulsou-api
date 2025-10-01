<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $table = 'attendances';

    protected $fillable = [
        'user_id',
        'class_id',
        'check_in',
        'check_out',
        'status',
        'class_status',
        'observations'
    ];

    public static function updateRules()
    {
        return [
            'status' => 'required|string|in:ABSENT,JUSTIFIED,PRESENT,LATE',
            'check_in' => 'required|date_format:H:i',
            'check_out' => 'required|date_format:H:i',
            'observations' => 'nullable|string',
        ];
    }

    public function class()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}