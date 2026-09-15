<?php

namespace App\Services\Scholarship;

use App\Models\ScholarshipPaymentBatch;

/**
 * Deterministic, collision-free filename for a paid batch's bank-file
 * export (design D5, sdd/becario-payment-bank-file-export/design).
 *
 * The exact literal convention is pending business confirmation with the
 * client — that is isolated to the single FORMAT constant below so the
 * decision can change without touching any caller.
 *
 * Campus slugging decision (design flagged this as an open question, no
 * concrete algorithm specified there — resolved here): uppercase, accents
 * transliterated to ASCII via an explicit map (mirrors the "never
 * iconv('//TRANSLIT')" spirit the PR2 serializer will use, since iconv
 * output is locale/build-dependent), then any remaining run of
 * non-alphanumeric characters collapsed to a single underscore.
 */
class BankPaymentFileName
{
    private const FORMAT = 'PAGO_%d_%s_%04d%02d_%d.TXT';

    /** @var array<string, string> */
    private const ACCENT_MAP = [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'Ñ' => 'N', 'Ü' => 'U',
    ];

    public static function forBatch(ScholarshipPaymentBatch $batch): string
    {
        return sprintf(
            self::FORMAT,
            $batch->generation_id,
            self::slugCampus($batch->campus),
            $batch->period_year,
            $batch->period_month,
            $batch->id
        );
    }

    private static function slugCampus(string $campus): string
    {
        $ascii = strtr(mb_strtoupper($campus), self::ACCENT_MAP);

        return (string) preg_replace('/[^A-Z0-9]+/', '_', $ascii);
    }
}
