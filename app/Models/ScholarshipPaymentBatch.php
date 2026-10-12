<?php

namespace App\Models;

use App\Enums\PaymentBatchType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipPaymentBatch extends Model
{
    protected $table = 'scholarship_payment_batches';

    protected $fillable = [
        'campus',
        'period_year',
        'period_month',
        // batch_type (sdd/pagos-lote-pago-adelantado): part of the batch
        // identity alongside campus/period_year/period_month — see the
        // 2026_10_12_100000 migration's new 4-column unique key.
        'batch_type',
        'refrend_count',
        'total_amount',
        'processed_by_id',
        'processed_at',
    ];

    protected $casts = [
        'generation_id'   => 'integer',
        'period_year'     => 'integer',
        'period_month'    => 'integer',
        'batch_type'      => PaymentBatchType::class,
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
