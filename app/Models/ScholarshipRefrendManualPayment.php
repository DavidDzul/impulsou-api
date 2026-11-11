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

    /**
     * API shape shared by the Pagos rows and the mark/revert responses.
     *
     * @return array{concept: string, method: string, reference: string, amount: string, paid_at: ?string, notes: ?string}
     */
    public function toPayload(): array
    {
        return [
            'concept'   => $this->concept,
            'method'    => (string) $this->getRawOriginal('method'),
            'reference' => $this->reference,
            'amount'    => (string) $this->amount,
            'paid_at'   => $this->paid_at?->toDateString(),
            'notes'     => $this->notes,
        ];
    }

    /**
     * Payloads of the given refrends' child rows in one query, grouped by
     * refrend id, Beca first then Apoyo.
     *
     * @param  array<int, int|string>  $refrendIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function payloadsByRefrend(array $refrendIds): array
    {
        return static::query()
            ->whereIn('refrend_id', $refrendIds)
            ->orderBy('refrend_id')
            ->orderByRaw("CASE concept WHEN 'beca' THEN 0 ELSE 1 END")
            ->get()
            ->groupBy('refrend_id')
            ->map(fn ($rows) => $rows->map(fn (self $row) => $row->toPayload())->all())
            ->all();
    }

    public function refrend(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRefrend::class, 'refrend_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
