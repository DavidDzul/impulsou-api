<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipWithholding extends Model
{
    /** Tolerance for float/rounding drift when deciding if a retention is fully settled. */
    public const SETTLEMENT_EPSILON = 0.005;

    protected $table = 'scholarship_withholdings';

    protected $fillable = [
        'user_id',
        'origin_refrend_id',
        'period_year',
        'period_month',
        'withheld_amount',
        'paid_amount',
        'status',
        'cause',
        'created_by_id',
        'settled_at',
    ];

    protected $casts = [
        'withheld_amount' => 'decimal:2',
        'paid_amount'     => 'decimal:2',
        'settled_at'      => 'datetime',
    ];

    protected $appends = ['remaining_amount'];

    public function getRemainingAmountAttribute(): string
    {
        return number_format(
            max(0, (float) $this->withheld_amount - (float) $this->paid_amount),
            2,
            '.',
            ''
        );
    }

    /**
     * Whether `$amount` is an exact settlement of the current remaining
     * balance, within the standard float/rounding tolerance. Amount
     * exactness is a pure per-row predicate — unlike window eligibility
     * (PayableWithholdingWindow), it needs no context beyond this row.
     */
    public function isFullSettlementAmount(float $amount): bool
    {
        return abs($amount - (float) $this->remaining_amount) <= self::SETTLEMENT_EPSILON;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING')->whereColumn('paid_amount', '<', 'withheld_amount');
    }

    public function originRefrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'origin_refrend_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ScholarshipWithholdingPayment::class, 'withholding_id');
    }

    /**
     * Recomputes paid_amount as the SUM of active (non-voided) child
     * payments — this is the ONLY writer of paid_amount. It is never
     * incremented/decremented directly, which makes any drift auto-sanable:
     * calling this again always restores the correct value from the
     * source-of-truth child rows.
     */
    public function recomputePaidAmount(): void
    {
        if ($this->status === 'CANCELLED') {
            return;
        }

        $sum = (float) $this->payments()->where('is_voided', false)->sum('amount');
        $this->paid_amount = number_format(round($sum, 2), 2, '.', '');

        $isSettled = $sum + self::SETTLEMENT_EPSILON >= (float) $this->withheld_amount;
        $this->status     = $isSettled ? 'PAID' : 'PENDING';
        $this->settled_at = $isSettled ? ($this->settled_at ?? now()) : null;

        $this->save();
    }
}
