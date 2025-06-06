<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessData extends Model
{
    use HasFactory;

    protected $table = 'business_data';

    protected $fillable = [
        'user_id',
        'bs_name',
        'bs_director',
        'bs_rfc',
        'bs_country',
        'bs_state',
        'bs_locality',
        'bs_adrress',
        'bs_telphone',
        'bs_line',
        'bs_other_line',
        'bs_description',
        'bs_website'
    ];

    public static function createRules()
    {
        return [
            'bs_name' => 'required|string|max:255',
            'bs_director' => 'required|string|max:255',
            'bs_rfc' => 'required|string|max:13',
            'bs_country' => 'required|string|max:100',
            'bs_state' => 'required|string|max:100',
            'bs_locality' => 'required|string|max:255',
            'bs_adrress' => 'required|string|max:255',
            'bs_telphone' => 'required|string|max:15',
            'bs_line' => 'required|string|max:255',
            'bs_other_line' => 'nullable|string|max:255',
            'bs_description' => 'required|string',
            'bs_website' => 'nullable|string|max:255',
        ];
    }

    public static function updateRules()
    {
        return [
            'bs_name' => 'sometimes|string|max:255',
            'bs_director' => 'sometimes|string|max:255',
            'bs_rfc' => 'sometimes|string|max:13',
            'bs_country' => 'sometimes|string|max:100',
            'bs_state' => 'sometimes|string|max:100',
            'bs_locality' => 'sometimes|string|max:255',
            'bs_adrress' => 'sometimes|string|max:255',
            'bs_telphone' => 'sometimes|string|max:15',
            'bs_line' => 'sometimes|string|max:255',
            'bs_other_line' => 'nullable|string|max:255',
            'bs_description' => 'sometimes|string',
            'bs_website' => 'nullable|string|max:255',
        ];
    }

    public function vacantPositions()
    {
        return $this->hasMany(VacantPosition::class, 'user_id', 'user_id');
    }
}