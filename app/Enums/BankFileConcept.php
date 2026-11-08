<?php

namespace App\Enums;

/**
 * sdd/archivo-pago-beca-apoyo: the two concepts the BBVA payment file is split
 * into. Each concept is its own downloadable file: Beca (scholarship) and
 * Apoyo (support).
 */
enum BankFileConcept: string
{
    case BECA  = 'beca';
    case APOYO = 'apoyo';

    /** Route segment (`batches/{batch}/export/{concept}`). */
    public function slug(): string
    {
        return $this->value;
    }

    /** Last filename segment (`..._{id}_BECA.TXT`). */
    public function fileSuffix(): string
    {
        return strtoupper($this->value);
    }

    /** Key of this concept's amount on a paidRows() row. */
    public function amountKey(): string
    {
        return $this->value . '_amount';
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom($slug);
    }
}
