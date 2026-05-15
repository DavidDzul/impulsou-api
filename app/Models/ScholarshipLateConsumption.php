<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipLateConsumption extends Model
{
    protected $table = 'scholarship_late_consumptions';

    public $timestamps = false;

    protected $fillable = [
        'scholarship_refrend_discount_id',
        'attendance_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Relations

    public function discount(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrendDiscount::class, 'scholarship_refrend_discount_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }
}
