<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ClassModel extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $casts = [
        'date' => 'date',
    ];

    protected $fillable = [
        'name',
        'date',
        'start_time',
        'end_time',
        'campus',
        'generation_id'
    ];

    public static function indexRules()
    {
        return [
            'campus'     => 'required|in:MERIDA,TIZIMIN,OXKUTZCAB,VALLADOLID',
            'generation_id' => 'required|exists:generations,id',
        ];
    }

    public static function createRules($request = null)
    {
        return [
            'name'       => 'required|string|max:255',
            // 'date'       => 'required|date',
            'date'       => [
                'required',
                'date',
                // Validamos que sea única combinando campus y generación
                Rule::unique('classes', 'date')->where(function ($query) use ($request) {
                    return $query->where('campus', $request->input('campus'))
                        ->where('generation_id', $request->input('generation_id'));
                }),
            ],
            'start_time' => 'required|date_format:H:i:s',
            'end_time'   => 'required|date_format:H:i:s|after:start_time',
            'campus'     => 'required|in:MERIDA,TIZIMIN,OXKUTZCAB,VALLADOLID',
            'generation_id' => 'required|exists:generations,id',
        ];
    }

    public static function updateRules()
    {
        return [
            'name'       => 'required|string|max:255',
            // 'date'       => 'required|date',
            // 'start_time' => 'required|date_format:H:i',
            // 'end_time'   => 'required|date_format:H:i|after:start_time',
        ];
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'class_id');
    }
}