<?php

namespace App\Services\Scholarship;

use App\Enums\PaymentBatchType;
use App\Models\ScholarshipPaymentBatch;

/**
 * Deterministic, collision-free filename for a paid batch's bank-file
 * export (design D5, sdd/becario-payment-bank-file-export/design).
 *
 * The exact literal convention is pending business confirmation with the
 * client — that is isolated to the two FORMAT constants below so the
 * decision can change without touching any caller.
 *
 * Campus slugging decision (design flagged this as an open question, no
 * concrete algorithm specified there — resolved here): uppercase, accents
 * transliterated to ASCII via an explicit map (mirrors the "never
 * iconv('//TRANSLIT')" spirit the PR2 serializer will use, since iconv
 * output is locale/build-dependent), then any remaining run of
 * non-alphanumeric characters collapsed to a single underscore.
 *
 * batch_type segment (sdd/pagos-lote-pago-adelantado, approved amendment
 * #2 — OVERRIDES the original design's "TYPE for both types" sketch): NORMAL
 * must stay BYTE-IDENTICAL to the pre-change filename (no segment at all),
 * so `PaymentBatchType::NORMAL->fileSlug()` returns null and FORMAT_NORMAL
 * (the original, untouched FORMAT) is used. Only CERT_UNID adds a segment,
 * via FORMAT_TYPED. A batch with no batch_type at all (null — a plain
 * in-memory model built without it, same as every pre-existing test here)
 * is also treated as NORMAL, never as an error.
 */
class BankPaymentFileName
{
    private const FORMAT_NORMAL = 'PAGO_%s_%04d%02d_%d.TXT';
    private const FORMAT_TYPED  = 'PAGO_%s_%s_%04d%02d_%d.TXT';

    /** @var array<string, string> */
    private const ACCENT_MAP = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'Ñ' => 'N', 'Ü' => 'U',
    ];

    public static function forBatch(ScholarshipPaymentBatch $batch): string
    {
        $typeSlug = self::typeSlug($batch->batch_type);

        if ($typeSlug === null) {
            return sprintf(
                self::FORMAT_NORMAL,
                self::slugCampus($batch->campus),
                $batch->period_year,
                $batch->period_month,
                $batch->id
            );
        }

        return sprintf(
            self::FORMAT_TYPED,
            self::slugCampus($batch->campus),
            $typeSlug,
            $batch->period_year,
            $batch->period_month,
            $batch->id
        );
    }

    /**
     * Accepts the enum-cast shape (`$batch->batch_type` via
     * ScholarshipPaymentBatch's cast) or null (no batch_type set at all —
     * every pre-PR1 test fixture here). Never a raw string: unlike
     * PaymentBatchService::rows() (DB::table(), raw strings),
     * ScholarshipPaymentBatch is always read through Eloquent, so the cast
     * always normalizes it to the enum object first.
     */
    private static function typeSlug(?PaymentBatchType $batchType): ?string
    {
        return $batchType?->fileSlug();
    }

    private static function slugCampus(string $campus): string
    {
        $ascii = strtr(mb_strtoupper($campus), self::ACCENT_MAP);

        return (string) preg_replace('/[^A-Z0-9]+/', '_', $ascii);
    }
}
