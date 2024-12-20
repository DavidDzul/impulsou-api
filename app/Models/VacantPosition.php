<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VacantPosition extends Model
{
    use HasFactory;
    protected $table = 'vacant_position';

    public function image()
    {
        return $this->hasOne(Image::class, 'user_id', 'user_id');
    }

    public function business()
    {
        return $this->hasOne(BusinessData::class, 'user_id', 'user_id');
    }
}
