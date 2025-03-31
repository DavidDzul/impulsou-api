<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    use HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'enrollment',
        'first_name',
        'last_name',
        'email',
        'phone',
        'workstation',
        'user_type',
        'campus',
        'generation_id',
        'password',
        'campus',
        'active',
    ];

    protected $attributes = [
        'campus' => '',
    ];
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public static function updateRulesProfile($userId)
    {
        return [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|unique:users,email,' . $userId,
            'phone' => 'sometimes|string|max:15',
            'password' => 'sometimes|string|min:3',
            'workstation' => 'sometimes|string|max:255',
        ];
    }

    public static function createRulesUser()
    {
        return [
            'enrollment' => 'required|string|min:9|max:9|unique:users,enrollment',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:15',
            'campus' => 'required|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
            'generation_id' => 'required|exists:generations,id',
            'password' => 'required|string|min:8',
        ];
    }

    public static function updateRulesUser($userId)
    {
        return [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|unique:users,email,' . $userId,
            'phone' => 'nullable|string|max:15',
            'enrollment' => 'sometimes|string|min:9|max:9|unique:users,enrollment,' . $userId,
            'password' => 'nullable|string|min:6',
            'active' => 'nullable|boolean',
            'user_type' => 'sometimes|string|in:BEC_ACTIVE,BEC_INACTIVE',
            'campus' => 'sometimes|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
            'generation_id' => 'sometimes|exists:generations,id',
        ];
    }

    public static function createRulesBusiness()
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:15',
            'workstation' => 'nullable|string|max:255',
            'campus' => 'required|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
            'password' => 'required|string|min:8',
        ];
    }

    public static function updateRulesBusiness($userId)
    {
        return [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|unique:users,email,' . $userId,
            'phone' => 'nullable|string|max:15',
            'workstation' => 'nullable|string|max:255',
            'campus' => 'sometimes|string|in:MERIDA,VALLADOLID,OXKUTZCAB,TIZIMIN',
            'password' => 'sometimes|string|min:8',
            'active' => 'nullable|boolean',
            'role' => 'sometimes|string|exists:roles,name',
        ];
    }

    public function agreement()
    {
        return $this->hasOne(BusinessAgreement::class, 'user_id')->latestOfMany();
    }

    public function listCompanyAgreements()
    {
        $agreements = BusinessAgreement::with('user:id,first_name,last_name,email')->get();

        return response()->json($agreements);
    }

    public function businessData()
    {
        return $this->hasOne(BusinessData::class, 'user_id');
    }

    public function businessAgreement()
    {
        return $this->hasOne(BusinessAgreement::class, 'user_id');
    }
}