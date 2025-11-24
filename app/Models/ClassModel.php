<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassModel extends Model
{
    use HasFactory;

    protected $table = 'classes';

    // protected $casts = [
    //     'start_time' => 'datetime:H:i:s',
    //     'end_time' => 'datetime:H:i:s',
    // ];
    protected $fillable = [
        'name',
        'date',
        'start_time',
        'end_time',
        'campus',
        'generation_id'
    ];

    public static function createRules()
    {
        return [
            'name'       => 'required|string|max:255',
            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i|after:start_time',
            'campus'     => 'required|in:MERIDA,TIZIMIN,OXKUTZCAB,VALLADOLID',
            'generation_id' => 'required|exists:generations,id',
        ];
    }

    public static function updateRules()
    {
        return [
            'name'       => 'required|string|max:255',
            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i|after:start_time',
        ];
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'class_id');
    }
}
