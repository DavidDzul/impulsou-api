<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassModel extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'name',
        'date',
        'start_time',
        'end_time',
        'campus'
    ];

    public static function createRules()
    {
        return [
            'name'       => 'required|string|max:255',
            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i|after:start_time',
            // 'campus'     => 'required|in:MERIDA,TIZIMIN,OXKUTZCAB,VALLADOLID',
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
}