<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Header row for one advance-payment batch (design "Schema /
 * scholarship_advance_payments"). Mirrors ScholarshipWithholding's style as
 * the closest existing precedent.
 */
class ScholarshipAdvancePayment extends Model
{
    use HasFactory;

    protected $table = 'scholarship_advance_payments';

    protected $fillable = [
        'user_id',
        'origin_refrend_id',
        'origin_period_year',
        'origin_period_month',
        'months_count',
        'total_amount',
        'status',
        'cause',
        'notes',
        'created_by_id',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function originRefrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'origin_refrend_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function months(): HasMany
    {
        return $this->hasMany(ScholarshipAdvancePaymentMonth::class, 'advance_payment_id');
    }
}
