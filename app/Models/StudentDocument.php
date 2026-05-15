<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDocument extends Model
{
    use HasFactory;

    protected $table = 'student_documents';

    protected $fillable = [
        'user_id',
        'period_year',
        'period_month',
        'document_type',
        'status',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'version',
        'rejected_reason',
    ];

    protected $casts = [
        'document_type' => DocumentType::class,
        'status'        => DocumentStatus::class,
        'file_size'     => 'integer',
        'version'       => 'integer',
    ];

    // Relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', DocumentStatus::PENDING->value);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', DocumentStatus::SUBMITTED->value);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForPeriod($query, int $year, int $month)
    {
        return $query->where('period_year', $year)->where('period_month', $month);
    }
}
