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
        'observations',
        'late_penalty_consumed',
        'late_penalty_consumed_refrend_id',
    ];

    protected $casts = [
        'late_penalty_consumed' => 'boolean',
    ];

    public static function validateHistoy()
    {
        return [
            'year' => 'required|integer',
        ];
    }

    public static function updateRules()
    {
        return [
            'status' => 'required|string|in:ABSENT,JUSTIFIED,PRESENT,LATE,JUSTIFIED_LATE,JUSTIFIED_ABSENCE',
            'check_in' => 'required|date_format:H:i',
            'check_out' => 'required|date_format:H:i',
            'observations' => 'nullable|string',
        ];
    }

    public function class()
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function latePenaltyRefrend()
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'late_penalty_consumed_refrend_id');
    }

    // Retardos sin justificar no consumidos dentro de un rango de fechas
    public function scopeUnconsumedLatesInRange($query, string $start, string $end)
    {
        return $query
            ->where('status', 'LATE')
            ->where('late_penalty_consumed', false)
            ->whereHas('class', fn($q) => $q->whereBetween('date', [$start, $end]));
    }
}
