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
        'num_visualizations',
        'num_vacancies',
        'unlimited'
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
