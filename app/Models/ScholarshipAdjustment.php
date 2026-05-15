<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipAdjustment extends Model
{
    use HasFactory;

    protected $table = 'scholarship_adjustments';

    protected $fillable = [
        'scholarship_refrend_id',
        'adjustment_type',
        'amount',
        'reason',
        'applied_by_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // Relations

    public function refrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'scholarship_refrend_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_id');
    }
}
