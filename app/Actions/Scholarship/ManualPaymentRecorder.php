<?php

namespace App\Actions\Scholarship;

use App\Enums\PaymentMethod;
use App\Exceptions\ManualPaymentException;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendManualPayment;
use App\Services\Scholarship\ManualPaymentConceptAmounts;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * sdd/bank-file-split-other-banks-cheques: writes the Beca/Apoyo child rows of
 * ONE manual payment (cheque or other-bank transfer). Runs inside the caller's
 * transaction, after the shared guards: server amounts are computed here, the
 * client's expected_amounts must match them (409 CONCEPT_SPLIT_CHANGED),
 * references are required only for concepts with amount > 0 and must be
 * distinct, and (method, normalized reference) must be free (pre-check under
 * lock + unique index as the race backstop).
 */
class ManualPaymentRecorder
{
    public function __construct(private ManualPaymentConceptAmounts $amounts)
    {
    }

    /**
     * @param array<string, mixed>                $references      concept => reference
     * @param array<string, mixed>                $expectedAmounts concept => decimal
     * @param Closure(string): ManualPaymentException $taken        builds the *_TAKEN error for a concept
     * @return array<int, ScholarshipRefrendManualPayment>
     *
     * @throws ValidationException
     * @throws ManualPaymentException
     */
    public function record(
        ScholarshipRefrend $refrend,
        PaymentMethod $channel,
        int $userId,
        array $references,
        array $expectedAmounts,
        ?string $paidAt,
        ?string $notes,
        Closure $taken,
        string $exception = ManualPaymentException::class
    ): array {
        $amounts = $this->amounts->forRefrend($refrend->id);

        if ($amounts === []) {
            throw ManualPaymentException::notPayable([
                ['code' => 'NOTHING_TO_PAY', 'message' => 'El refrendo no tiene saldo por pagar'],
            ]);
        }

        $this->assertExpectedAmounts($amounts, $expectedAmounts, $exception);
        $normalized = $this->validatedReferences($amounts, $references);

        foreach ($normalized as $concept => $reference) {
            $this->assertFree($channel, $refrend->id, $reference['normalized'], $concept, $taken);
        }

        $rows = [];
        try {
            foreach ($normalized as $concept => $reference) {
                $rows[] = ScholarshipRefrendManualPayment::create([
                    'refrend_id'           => $refrend->id,
                    'concept'              => $concept,
                    'method'               => $channel->value,
                    'reference'            => $reference['reference'],
                    'reference_normalized' => $reference['normalized'],
                    'amount'               => $amounts[$concept],
                    'paid_at'              => $paidAt ?? now()->toDateString(),
                    'notes'                => $notes,
                    'created_by_id'        => $userId,
                ]);
            }
        } catch (QueryException $e) {
            // A concurrent mark committed the same reference after the pre-check.
            foreach ($normalized as $concept => $reference) {
                $this->assertFree($channel, $refrend->id, $reference['normalized'], $concept, $taken);
            }
            throw $e;
        }

        return $rows;
    }

    /**
     * @param array<string, string> $amounts
     * @param array<string, mixed>  $expected
     * @param class-string<ManualPaymentException> $exception
     */
    private function assertExpectedAmounts(array $amounts, array $expected, string $exception): void
    {
        foreach (['beca', 'apoyo'] as $concept) {
            if ($this->cents($amounts[$concept] ?? '0') !== $this->cents($expected[$concept] ?? '0')) {
                throw $exception::conceptSplitChanged();
            }
        }
    }

    /**
     * @param array<string, string> $amounts
     * @param array<string, mixed>  $references
     * @return array<string, array{reference: string, normalized: string}>
     */
    private function validatedReferences(array $amounts, array $references): array
    {
        $errors = [];
        $clean  = [];

        foreach (['beca', 'apoyo'] as $concept) {
            $value = trim((string) ($references[$concept] ?? ''));

            if (!isset($amounts[$concept])) {
                if ($value !== '') {
                    $errors["references.$concept"] = ['Este concepto no tiene monto por pagar; no lleva referencia.'];
                }
                continue;
            }

            if ($value === '') {
                $errors["references.$concept"] = ['La referencia es obligatoria.'];
                continue;
            }

            $clean[$concept] = ['reference' => $value, 'normalized' => mb_strtolower($value)];
        }

        if (isset($clean['beca'], $clean['apoyo']) && $clean['beca']['normalized'] === $clean['apoyo']['normalized']) {
            $errors['references.apoyo'] = ['La referencia de Apoyo debe ser distinta a la de Beca.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** @param Closure(string): ManualPaymentException $taken */
    private function assertFree(PaymentMethod $channel, int $refrendId, string $normalized, string $concept, Closure $taken): void
    {
        $exists = ScholarshipRefrendManualPayment::where('method', $channel->value)
            ->where('reference_normalized', $normalized)
            ->where('refrend_id', '!=', $refrendId)
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw $taken($concept);
        }
    }

    private function cents(mixed $money): int
    {
        return (int) round(((float) $money) * 100);
    }
}
