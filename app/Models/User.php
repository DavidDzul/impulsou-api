<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Auth\Passwords\CanResetPassword;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, CanResetPassword;
    use HasRoles;

    /**
     * Roles that are legitimate to assign to a user_type=BUSINESS account.
     *
     * These are the membership-tier roles seeded in RoleSeeder under
     * "PANEL DE USUARIO" (they gate CANDIDATES_VIEW / CREATE_VACANT_JR) and
     * are the roles database/seeders/UserSeeder.php + UserFactory assign to
     * BUSINESS-type accounts (e.g. assignRole('DIAMOND')).
     *
     * Staff/admin roles (ROOT, ROOT_CAMPUS, YUCATAN, ATTENDANCE,
     * ADMIN_STUDENT, ROOT_JOB, ADMIN_JOB, ROOT_ADMINISTRATION) have no
     * business semantics and must NEVER be assignable through the business
     * store/update endpoints — see BusinessController security fix.
     */
    public const BUSINESS_ROLES = ['BASIC', 'BRONZE', 'SILVER', 'GOLD', 'PLATINUM', 'DIAMOND'];

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
        'active' => 'boolean',
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
            'password' => 'sometimes|string|min:6',
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
            'role' => ['required', 'string', Rule::in(self::BUSINESS_ROLES)],
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
            'role' => ['sometimes', 'string', Rule::in(self::BUSINESS_ROLES)],
        ];
    }

    // Scholarship relationships

    public function scholarshipProfile()
    {
        return $this->hasOne(ScholarshipProfile::class, 'user_id');
    }

    public function scholarshipRefrends()
    {
        return $this->hasMany(ScholarshipRefrend::class, 'user_id');
    }

    public function studentDocuments()
    {
        return $this->hasMany(StudentDocument::class, 'user_id');
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

    public function paymentData(): HasOne
    {
        return $this->hasOne(ScholarshipPaymentData::class, 'user_id');
    }

    // VALIDACIONES DE ROLES
    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('name', $roles)->exists();
    }

    public function isRoot(): bool
    {
        return $this->hasAnyRole(['ROOT', 'ROOT_ADMINISTRATION']);
    }

    public function isRootJob(): bool
    {
        return $this->hasAnyRole(['ROOT_JOB']);
    }

    public function mainRole()
    {
        return $this->roles->first();
    }
}
