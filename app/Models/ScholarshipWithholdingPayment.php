<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipWithholdingPayment extends Model
{
    protected $table = 'scholarship_withholding_payments';

    protected $fillable = [
        'withholding_id',
        'applied_refrend_id',
        'amount',
        'created_by_id',
        'is_voided',
        'voided_at',
        'voided_by_id',
        'void_reason',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'is_voided'  => 'boolean',
        'voided_at'  => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_voided', false);
    }

    public function withholding(): BelongsTo
    {
        return $this->belongsTo(ScholarshipWithholding::class, 'withholding_id');
    }

    public function appliedRefrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'applied_refrend_id');
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
