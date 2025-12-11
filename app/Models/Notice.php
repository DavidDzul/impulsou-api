<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    use HasFactory;


    protected $table = 'notices';


    protected $fillable = [
        'message',
        'campus',
        'global',
        'active',
    ];

    public static function createRules()
    {
        return [
            'message' => 'required|string',
            'campus' => 'required|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
            'global' => 'nullable|boolean',
            'active' => 'required|boolean',
        ];
    }

    public static function updateRules()
    {
        return [
            'message' => 'required|string',
            'campus' => 'required|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
            'global' => 'nullable|boolean',
            'active' => 'required|boolean',
        ];
    }
}
