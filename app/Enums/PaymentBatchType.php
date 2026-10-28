<?php

namespace App\Enums;

/**
 * sdd/pagos-lote-pago-adelantado, design "Architecture Decisions" > Type
 * representation: a string-backed enum (mirrors RefrendStatus/
 * ScholarshipType), not a magic string or boolean `is_cert_unid` — this can
 * grow to more types later without a breaking rename.
 */
enum PaymentBatchType: string
{
    case NORMAL    = 'NORMAL';
    case CERT_UNID = 'CERT_UNID';

    /**
     * Segment a refrend belongs to for a given scholarship_profiles flag
     * (true = pago adelantado / CERT_UNID). A missing profile is NORMAL.
     */
    public static function forCertUnidFlag(bool $isCertUnid): self
    {
        return $isCertUnid ? self::CERT_UNID : self::NORMAL;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Filename segment consumed by BankPaymentFileName (amendment #2,
     * sdd/pagos-lote-pago-adelantado/amendments): NORMAL must keep the
     * CURRENT filename byte-identical, so it returns null here (no segment
     * at all) instead of the literal 'NORMAL'. Only CERT_UNID returns a
     * non-null slug — 'CERTUNID' (alphanumeric only, no underscore, so it
     * never clashes with the FORMAT's own underscore delimiters).
     */
    public function fileSlug(): ?string
    {
        return match ($this) {
            self::NORMAL    => null,
            self::CERT_UNID => 'CERTUNID',
        };
    }
}
