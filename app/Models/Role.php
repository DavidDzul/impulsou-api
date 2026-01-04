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

    public function configuration(): HasOne
    {
        return $this->hasOne(RoleConfiguration::class, 'role_id');
    }


    public static function updateRules()
    {
        return [
            'num_visualizations' => 'required|integer|min:0',
            'num_vacancies'      => 'required|integer|min:0',
            'unlimited'          => 'boolean',
            'permissions_ids'    => 'array',
            'permissions_ids.*'  => 'exists:permissions,id',
        ];
    }
}
