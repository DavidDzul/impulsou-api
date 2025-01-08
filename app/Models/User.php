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
        'name',
        'email',
        'password',
        'campus'
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


    public function images()
    {
        return $this->hasOne(Image::class, 'user_id', 'id');
    }

    public function curriculums()
    {
        return $this->hasOne(Curriculum::class, 'user_id', 'id');
    }

    public function workExperiences()
    {
        return $this->hasMany(WorkExperience::class, 'user_id', 'id');
    }

    public function academicInformations()
    {
        return $this->hasMany(AcademicInformation::class, 'user_id', 'id');
    }

    public function continuingEducations()
    {
        return $this->hasMany(ContinuingEducation::class, 'user_id', 'id');
    }

    public function technicalKnowledges()
    {
        return $this->hasMany(TechnicalKnowledge::class, 'user_id', 'id');
    }

    public function vacantPositions()
    {
        return $this->hasMany(VacantPosition::class, 'user_id');
    }
}