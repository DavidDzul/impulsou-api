<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Child row for one advanced future period (design "Schema /
 * scholarship_advance_payment_months"). Carries the divergence-tracking
 * fields (design D4): settled_resolution_type, divergence_reason,
 * reached_at — written by RecordPaymentSituationAction's reconciliation
 * block (PR4) when the advanced period finally arrives.
 */
class ScholarshipAdvancePaymentMonth extends Model
{
    use HasFactory;

    protected $table = 'scholarship_advance_payment_months';

    protected $fillable = [
        'advance_payment_id',
        'user_id',
        'period_year',
        'period_month',
        'amount',
        'refrend_id',
        'status',
        'reached_at',
        'settled_resolution_type',
        'divergence_reason',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'reached_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function advancePayment(): BelongsTo
    {
        return $this->belongsTo(ScholarshipAdvancePayment::class, 'advance_payment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function refrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'refrend_id');
    }
}
