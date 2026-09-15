<?php

namespace App\Services\Scholarship;

/**
 * Single rule set for BBVA bank-file data, extracted verbatim from the
 * bank's own macro `ValidateRow` logic (ground truth — byte-verified
 * against two real sample files, sdd/becario-payment-bank-file-export/design
 * D2). Pure; no DB, no framework.
 *
 * Consumed by two callers so the rules are enforced exactly once:
 *   - PaymentReadinessEvaluator (payment-time, forward-looking, PR3)
 *   - the export path (export-time, protects already-PAID legacy batches, PR3/PR4)
 *
 * RFC check is intentionally NOT a full RFC checksum/homoclave validation —
 * only the loose structural check the bank's macro itself performs
 * (positions 1-4 alphabetic, 5-6 any 2-digit year, 7-8 month 01-12, 9-10 day
 * 01-31). Do not add stricter validation than the macro has; over-validating
 * could reject RFCs the bank itself would accept.
 */
class BankDataValidator
{
    private const RFC_PATTERN = '/^[A-Z]{4}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])/';

    /**
     * @return array<int, array{code: string, message: string}>
     */
    public function validate(?string $accountNumber, ?string $rfc, string $totalToPay): array
    {
        $reasons = [];

        if (!$this->isValidAccountNumber($accountNumber)) {
            $reasons[] = [
                'code'    => 'INVALID_ACCOUNT_NUMBER',
                'message' => 'Número de cuenta inválido: debe ser numérico de 9 o 10 dígitos',
            ];
        }

        if (!$this->isValidRfc($rfc)) {
            $reasons[] = [
                'code'    => 'INVALID_RFC',
                'message' => 'RFC con estructura inválida',
            ];
        }

        if (!$this->isValidAmount($totalToPay)) {
            $reasons[] = [
                'code'    => 'NOTHING_TO_PAY',
                'message' => 'Monto a pagar menor o igual a cero',
            ];
        }

        return $reasons;
    }

    /** Purely numeric, exactly 9 or 10 digits. */
    private function isValidAccountNumber(?string $accountNumber): bool
    {
        if ($accountNumber === null) {
            return false;
        }

        return (bool) preg_match('/^\d{9,10}$/', $accountNumber);
    }

    /** Blank/null is valid; otherwise must pass the loose structural date check. */
    private function isValidRfc(?string $rfc): bool
    {
        if ($rfc === null || $rfc === '') {
            return true;
        }

        return (bool) preg_match(self::RFC_PATTERN, $rfc);
    }

    /** Numeric and strictly greater than zero. */
    private function isValidAmount(string $totalToPay): bool
    {
        return is_numeric($totalToPay) && (float) $totalToPay > 0;
    }
}
