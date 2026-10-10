<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * sdd/telmex-cobertura-iu, design D7/D8. Ledger status (ACTIVA/EN_COBRO/
 * LIQUIDADA/CANCELADA) is recomputed exclusively by TelmexCoverageLedger
 * (PR3a) — this model stays a thin persistence layer in PR1.
 */
class TelmexCoverage extends Model
{
    protected $table = 'scholarship_telmex_coverages';

    protected $fillable = [
        'user_id',
        'scholarship_type_at_activation',
        'start_period',
        'end_period',
        'status',
        'notes',
        'activated_by_id',
        'ended_by_id',
        'ended_at',
        'cancelled_by_id',
        'cancelled_at',
        'cancel_reason',
        'settled_at',
    ];

    protected $casts = [
        'start_period' => 'date',
        'end_period'   => 'date',
        'ended_at'     => 'datetime',
        'cancelled_at' => 'datetime',
        'settled_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TelmexCoveragePayment::class, 'coverage_id');
    }

    public function coveredRefrends(): HasMany
    {
        return $this->hasMany(ScholarshipRefrend::class, 'snapshot_telmex_coverage_id');
    }

    public function scopeNotCancelled($query)
    {
        return $query->where('status', '!=', 'CANCELADA');
    }
}
