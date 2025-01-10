<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_id',
        'vacant_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function business()
    {
        return $this->belongsTo(BusinessData::class, 'business_id',);
    }

    public function vacant()
    {
        return $this->belongsTo(VacantPosition::class, 'vacant_id',);
    }
}