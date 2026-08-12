<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Role extends SpatieRole
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = ['name'];

    public static function createOrUpdateRules()
    {
        return [
            'unlimited_jobs'          => 'boolean',
            'num_job_vacancies' => 'required|integer|min:0',
            'unlimited_professionals'          => 'boolean',
            'num_professional_vacancies' => 'required|integer|min:0',
            'unlimited_jr'          => 'boolean',
            'num_jr_vacancies' => 'required|integer|min:0',
            'unlimited_visualizations'          => 'boolean',
            'num_visualizations' => 'required|integer|min:0',
            'permissions_ids'    => 'array',
            'permissions_ids.*'  => 'exists:permissions,id',
        ];
    }

    public function configuration()
    {
        return $this->hasOne(RoleConfiguration::class, 'role_id');
    }
}
