<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessAgreement extends Model
{
    use HasFactory;
    protected $table = 'business_agreements';

    protected $fillable = [
        'user_id',
        'start_date',
        'end_date'
    ];

    public static function createRules()
    {
        return [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ];
    }
}