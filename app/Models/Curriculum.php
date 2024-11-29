<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Curriculum extends Model
{
    use HasFactory;

    protected $table = 'curriculum';

    // Permitir que esos campos sean actualizados masivamente.
    // protected $fillable = [
    //     'first_name',
    //     'last_name',
    //     'email',
    // ];

    // Todos los campos esta habilitados para actualizarlo masivamente.
    protected $guarded = [];
}
