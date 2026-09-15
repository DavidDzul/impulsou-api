<?php

namespace App\Services\Scholarship;

/**
 * Pure, DB-free serializer for the BBVA bank-file fixed-width record
 * format (design Interfaces/Contracts table,
 * sdd/becario-payment-bank-file-export/design). Turns an array of payable
 * rows into the exact 108-char + CRLF byte layout the bank's own macro
 * produces — golden-fixture tested byte-for-byte against two real sample
 * files at tests/Fixtures/bank/.
 *
 * Row shape expected per row (matches PaymentBatchService::paidRows(), PR3,
 * not yet wired to this class):
 *   ['account_number' => ?string, 'rfc' => ?string, 'total_to_pay' => string, 'snapshot_name' => string]
 *
 * Assumes pre-validated input. BankDataValidator (PR1) is the single gate
 * for account/RFC/amount correctness, enforced by both
 * PaymentReadinessEvaluator (payment-time) and the export controller
 * (export-time, PR3/PR4) BEFORE rows ever reach this class. This class does
 * not re-validate — it only formats. An out-of-contract value that somehow
 * reaches serialize() (e.g. an 11-digit account number) is not rejected
 * here: str_pad() never shortens a string already longer than its target
 * length, so it is passed through unchanged rather than truncated.
 */
class BankPaymentFileSerializer
{
    private const TIPO = '99';
    private const BANCO = '001';
    private const PLAZA = '001';

    /**
     * Explicit transliteration map mirroring the bank macro's
     * `RemoveTrash` (design's ground-truth table + note on the Nombre
     * field). Deliberately not `iconv('//TRANSLIT')`, whose output is
     * locale- and build-dependent.
     *
     * @var array<string, string>
     */
    private const ACCENT_MAP = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U',
    ];

    /**
     * @param array<int, array{account_number: ?string, rfc: ?string, total_to_pay: string, snapshot_name: string}> $rows
     */
    public function serialize(array $rows): string
    {
        $body = '';
        $consecutivo = 1;

        foreach ($rows as $row) {
            $body .= $this->serializeRow($consecutivo, $row) . "\r\n";
            $consecutivo++;
        }

        return $body;
    }

    /**
     * @param array{account_number: ?string, rfc: ?string, total_to_pay: string, snapshot_name: string} $row
     */
    private function serializeRow(int $consecutivo, array $row): string
    {
        return
            $this->consecutivo($consecutivo) .
            $this->rfc($row['rfc']) .
            self::TIPO .
            $this->cuenta($row['account_number']) .
            $this->importe($row['total_to_pay']) .
            $this->nombre($row['snapshot_name']) .
            self::BANCO .
            self::PLAZA;
    }

    /** 1-indexed position within THIS serialize() call, zero-padded to 9. */
    private function consecutivo(int $consecutivo): string
    {
        return str_pad((string) $consecutivo, 9, '0', STR_PAD_LEFT);
    }

    /** Left-justified, space-padded to 16. Blank/null input -> 16 spaces. */
    private function rfc(?string $rfc): string
    {
        return str_pad((string) $rfc, 16, ' ', STR_PAD_RIGHT);
    }

    /** 10-digit zero-padded account number, then space-padded to 20 total. */
    private function cuenta(?string $accountNumber): string
    {
        $zeroPadded = str_pad((string) $accountNumber, 10, '0', STR_PAD_LEFT);

        return str_pad($zeroPadded, 20, ' ', STR_PAD_RIGHT);
    }

    /**
     * Centavos as a zero-padded 15-digit string. String-based conversion
     * is mandatory (design D7) — float multiplication can produce
     * off-by-one-centavo drift for values whose internal float
     * representation sits fractionally below the intended integer (e.g.
     * '19.99' * 100 can be represented as ~1998.999999999998, and an
     * (int) cast truncates that toward zero instead of 1999). Removing the
     * decimal point from the already-2-decimal string is exact.
     *
     * $totalToPay is always a number_format(..., 2, '.', '')-shaped
     * 2-decimal numeric string.
     */
    private function importe(string $totalToPay): string
    {
        $centavos = (int) str_replace('.', '', $totalToPay);

        return str_pad((string) $centavos, 15, '0', STR_PAD_LEFT);
    }

    /**
     * Accents/ñ/ü stripped via the explicit map, periods replaced with
     * spaces, uppercased, then truncated/space-padded to 40. The real
     * sample fixtures are already all-caps with no accents — uppercasing
     * here is a documented, deliberate decision (not a silent assumption):
     * our own source data is not guaranteed to already be uppercase, and
     * the bank macro's `RemoveTrash` does not itself force case, so this
     * class forces it to guarantee fixture-identical output regardless of
     * how the name is stored upstream.
     */
    private function nombre(string $snapshotName): string
    {
        $normalized = strtr($snapshotName, self::ACCENT_MAP);
        $normalized = str_replace('.', ' ', $normalized);
        $normalized = mb_strtoupper($normalized);
        $truncated = mb_substr($normalized, 0, 40);

        return str_pad($truncated, 40, ' ', STR_PAD_RIGHT);
    }
}
