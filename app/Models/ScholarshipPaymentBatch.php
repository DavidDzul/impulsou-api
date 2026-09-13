<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipPaymentBatch extends Model
{
    protected $table = 'scholarship_payment_batches';

    protected $fillable = [
        'generation_id',
        'campus',
        'period_year',
        'period_month',
        'refrend_count',
        'total_amount',
        'processed_by_id',
        'processed_at',
    ];

    protected $casts = [
        'generation_id'   => 'integer',
        'period_year'     => 'integer',
        'period_month'    => 'integer',
        'refrend_count'   => 'integer',
        'total_amount'    => 'decimal:2',
        'processed_by_id' => 'integer',
        'processed_at'    => 'datetime',
    ];

    /** Every refrend paid as part of this batch. */
    public function refrends(): HasMany
    {
        return $this->hasMany(ScholarshipRefrend::class, 'payment_batch_id');
    }

    /** The admin who processed this batch. */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_id');
    }
}
