<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserBusinessMap extends Model
{
    use HasFactory;

    protected $table = 'user_business_map';

    protected $fillable = [
        'user_id',
        'business_id',
    ];
}