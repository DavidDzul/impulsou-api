<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** sdd/telmex-cobertura-iu, design D8 — mirrors ScholarshipWithholdingPayment. */
class TelmexCoveragePayment extends Model
{
    protected $table = 'scholarship_telmex_coverage_payments';

    protected $fillable = [
        'coverage_id',
        'amount',
        'paid_at',
        'reference',
        'notes',
        'created_by_id',
        'is_voided',
        'voided_at',
        'voided_by_id',
        'void_reason',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'paid_at'    => 'date',
        'is_voided'  => 'boolean',
        'voided_at'  => 'datetime',
    ];

    public function coverage(): BelongsTo
    {
        return $this->belongsTo(TelmexCoverage::class, 'coverage_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
    }
}
