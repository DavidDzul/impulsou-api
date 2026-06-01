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
        'workflow_status',
        'resolution_type',
        'resolution_cause',
        'resolution_notes',
        'suspension_percentage',
        'base_amount',
        'discount_percentage',
        'discount_amount',
        'final_amount',
        'amount_pending_from_previous',
        'carryover_amount',
        'carryover_from_refrend_id',
        'snapshot_name',
        'snapshot_generation',
        'snapshot_generation_id',
        'snapshot_campus',
        'snapshot_scholarship_type',
        'average_grade_snapshot',
        'missing_subjects_snapshot',
        'attendance_summary_snapshot',
        'atencion_observations',
        'atencion_labels',
        'atencion_reviewed_by_id',
        'atencion_reviewed_at',
        'pedagogia_observations',
        'pedagogia_reviewed_by_id',
        'pedagogia_reviewed_at',
        'notified_by_id',
        'notified_at',
        'notification_method',
        'locked_at',
        'locked_by_id',
        'carryover_months_count',
        'carryover_months_detail',
        'suspension_scope',
        'pedagogia_resolved_at',
        'pedagogia_resolved_by_id',
        'attendance_penalty_override',
    ];

    protected $casts = [
        'refrend_type'                 => RefrendType::class,
        'status'                       => RefrendStatus::class,
        'snapshot_scholarship_type'    => ScholarshipType::class,
        'base_amount'                  => 'decimal:2',
        'discount_percentage'          => 'decimal:2',
        'discount_amount'              => 'decimal:2',
        'final_amount'                 => 'decimal:2',
        'amount_pending_from_previous' => 'decimal:2',
        'carryover_amount'             => 'decimal:2',
        'average_grade_snapshot'       => 'decimal:2',
        'missing_subjects_snapshot'    => 'integer',
        'attendance_summary_snapshot'   => 'array',
        'attendance_penalty_override'   => 'boolean',
        'snapshot_generation_id'       => 'integer',
        'atencion_labels'              => 'array',
        'atencion_reviewed_at'         => 'datetime',
        'pedagogia_reviewed_at'        => 'datetime',
        'pedagogia_resolved_at'        => 'datetime',
        'carryover_months_count'       => 'integer',
        'notified_at'                  => 'datetime',
        'locked_at'                    => 'datetime',
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

    public function generation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Generation::class, 'snapshot_generation_id');
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

    public function incidents(): HasMany
    {
        return $this->hasMany(ScholarshipRefrendIncident::class, 'scholarship_refrend_id');
    }

    public function carryoverSource(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'carryover_from_refrend_id');
    }

    public function notifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_by_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(ScholarshipRefrendTransition::class, 'scholarship_refrend_id');
    }

    public function pedagogiaResolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pedagogia_resolved_by_id');
    }

    // Scopes

    public function scopeForGeneration($query, ?int $generationId)
    {
        if ($generationId === null) {
            return $query;
        }
        return $query->where('snapshot_generation_id', $generationId);
    }

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

    /**
     * A refrend is locked when:
     *  - workflow_status is PAID or CANCELLED (new workflow), OR
     *  - locked_at is set (legacy lock mechanism), OR
     *  - the legacy status enum reports locked (AUTHORIZED or PAID)
     *
     * All three conditions are checked for full backwards compatibility.
     */
    public function isLocked(): bool
    {
        // New workflow terminal states
        if (in_array($this->workflow_status, ['CLOSED', 'CANCELLED'])) {
            return true;
        }

        // New workflow active states — explicitly unlocked regardless of status enum
        if (in_array($this->workflow_status, ['DRAFT', 'CON_INCIDENCIA', 'PENDIENTE_NOTIFICACION', 'LISTO_PARA_PAGO'])) {
            return false;
        }

        // Legacy: PAID was previously used as terminal
        if ($this->workflow_status === 'PAID') {
            return true;
        }

        // Legacy: locked_at column
        if ($this->locked_at !== null) {
            return true;
        }

        // Legacy enum fallback for old records without explicit workflow_status
        return $this->status->isLocked();
    }
}
