<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoleConfiguration extends Model
{
    use HasFactory;

    protected $table = 'role_configuration';

    protected $fillable = [
        'role_id',
        'unlimited_jobs',
        'num_job_vacancies',
        'unlimited_professionals',
        'num_professional_vacancies',
        'unlimited_jr',
        'num_jr_vacancies',
        'unlimited_visualizations',
        'num_visualizations',
        // 'num_vacancies',
        // 'unlimited'
    ];

    protected $casts = [
        'unlimited_jobs' => 'boolean',
        'unlimited_professionals' => 'boolean',
        'unlimited_jr' => 'boolean',
        'unlimited_visualizations' => 'boolean',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
