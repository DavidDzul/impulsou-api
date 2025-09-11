<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $table = 'attendances';

    protected $fillable = [
        'user_id',
        'class_id',
        'check_in',
        'check_out',
        'status',
        'class_status',
        'observations'
    ];

    public function class()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }
}