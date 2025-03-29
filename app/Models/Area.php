<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use HasFactory;

    protected $table = 'areas';

    protected $fillable = [
        'name',
    ];

    public static function createRules()
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }

    public static function updateRules()
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }
}