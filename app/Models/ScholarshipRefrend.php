<?php

namespace App\Models;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipRefrend extends Model
{
    use HasFactory;

    protected $table = 'scholarship_refrends';

    protected $fillable = [
        'user_id',
        'period_year',
        'period_month',
        'refrend_type',
        'status',
        'base_amount',
        'discount_percentage',
        'discount_amount',
        'final_amount',
        'amount_pending_from_previous',
        'snapshot_name',
        'snapshot_generation',
        'snapshot_campus',
        'snapshot_scholarship_type',
        'atencion_observations',
        'atencion_labels',
        'atencion_reviewed_by_id',
        'atencion_reviewed_at',
        'pedagogia_observations',
        'pedagogia_reviewed_by_id',
        'pedagogia_reviewed_at',
        'locked_at',
        'locked_by_id',
    ];

    protected $casts = [
        'refrend_type'                => RefrendType::class,
        'status'                      => RefrendStatus::class,
        'snapshot_scholarship_type'   => ScholarshipType::class,
        'base_amount'                 => 'decimal:2',
        'discount_percentage'         => 'decimal:2',
        'discount_amount'             => 'decimal:2',
        'final_amount'                => 'decimal:2',
        'amount_pending_from_previous' => 'decimal:2',
        'atencion_labels'             => 'array',
        'atencion_reviewed_at'        => 'datetime',
        'pedagogia_reviewed_at'       => 'datetime',
        'locked_at'                   => 'datetime',
    ];

    protected $appends = ['total_to_pay'];

    public function getTotalToPayAttribute(): string
    {
        return number_format(
            (float) $this->final_amount + (float) $this->amount_pending_from_previous,
            2,
            '.',
            ''
        );
    }

    // Relations

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function atencionReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atencion_reviewed_by_id');
    }

    public function pedagogiaReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pedagogia_reviewed_by_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_id');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(ScholarshipRefrendDiscount::class, 'scholarship_refrend_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(ScholarshipAdjustment::class, 'scholarship_refrend_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ScholarshipRefrendLog::class, 'scholarship_refrend_id');
    }

    // Scopes

    public function scopeForPeriod($query, int $year, int $month)
    {
        return $query->where('period_year', $year)->where('period_month', $month);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeLocked($query)
    {
        return $query->whereNotNull('locked_at');
    }

    // Helpers

    public function isLocked(): bool
    {
        return $this->status->isLocked();
    }
}
