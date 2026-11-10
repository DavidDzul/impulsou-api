<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * sdd/bank-file-split-other-banks-cheques: the Beca or Apoyo part of a cheque /
 * manual-transfer payment, with its frozen amount and reference.
 */
class ScholarshipRefrendManualPayment extends Model
{
    use HasFactory;

    public const CONCEPT_BECA  = 'beca';
    public const CONCEPT_APOYO = 'apoyo';

    protected $table = 'scholarship_refrend_manual_payments';

    protected $fillable = [
        'refrend_id',
        'concept',
        'method',
        'reference',
        'reference_normalized',
        'amount',
        'paid_at',
        'notes',
        'created_by_id',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_at' => 'date',
    ];

    public function refrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'refrend_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
