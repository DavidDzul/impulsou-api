<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceToken extends Model
{
    use HasFactory;

    protected $table = 'attendance_tokens';

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'used'
    ];

    public static function createRules()
    {
        return [
            'user_id' => 'required|exists:users,id',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
