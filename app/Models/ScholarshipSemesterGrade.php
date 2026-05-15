<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipSemesterGrade extends Model
{
    use HasFactory;

    protected $table = 'scholarship_semester_grades';

    protected $fillable = [
        'user_id',
        'semester_year',
        'semester_period',
        'grade',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'uploaded_by_id',
    ];

    protected $casts = [
        'grade' => 'decimal:2',
    ];

    protected $appends = ['semester_label'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function getSemesterLabelAttribute(): string
    {
        return $this->semester_period === 1
            ? "Ene–Jul {$this->semester_year}"
            : "Ago–Dic {$this->semester_year}";
    }
}
