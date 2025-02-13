<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Generation extends Model
{
    use HasFactory;

    protected $fillable = [
        'generation_name',
        'campus',
        'generation_active',
    ];

    public static $createRules = [
        'generation_name' => 'required|string|max:255',
        'campus' => 'required|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
        'generation_active' => 'nullable|boolean',
    ];
}