<?php

namespace Tests\Unit;

use App\Services\Scholarship\BankDataValidator;
use App\Services\Scholarship\PaymentReadinessEvaluator;
use Tests\TestCase;

/**
 * Unit tests for PaymentReadinessEvaluator — the single source of truth for
 * payment-readiness blocking reasons (design D-none, "Interfaces / Contracts"
 * table in sdd/becario-payment-file-generation/design).
 *
 * $refrendRow is duck-typed: a stdClass mirrors a DB::table() row as produced
 * by PaymentBatchService::rows(); no Eloquent bootstrapping needed here.
 *
 * PR3 (sdd/becario-payment-bank-file-export/design D2) adds two required,
 * non-defaulted params (account number, RFC) so `evaluate()` also enforces
 * BankDataValidator's account/RFC rules. Every call below now passes a
 * VALID account number + RFC by default via `evaluate()`'s named-argument
 * defaults baked into `makeRow()`/helper params, so the original 5 codes'
 * behavior is unchanged unless a test explicitly overrides them.
 */
class PaymentReadinessEvaluatorTest extends TestCase
{
    private PaymentReadinessEvaluator $evaluator;

    /** A structurally valid account number, per BankDataValidator's own rule (9-10 digits). */
    private const VALID_ACCOUNT_NUMBER = '0123456789';

    /** A structurally valid RFC, per BankDataValidator's own rule (matches BankDataValidatorTest's example). */
    private const VALID_RFC = 'PEPJ800101ABC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new PaymentReadinessEvaluator(new BankDataValidator());
    }

    private function makeRow(array $overrides = []): object
    {
        return (object) array_merge([
            'workflow_status'  => 'LISTO_PARA_PAGO',
            'locked_at'        => null,
            'payment_batch_id' => null,
            'status'           => 'DRAFT',
            'final_amount'     => 1000.00,
        ], $overrides);
    }

    private function evaluate(
        object $row,
        bool $hasEnrollment,
        bool $hasPaymentData,
        ?string $accountNumber = self::VALID_ACCOUNT_NUMBER,
        ?string $rfc = self::VALID_RFC
    ): array {
        return $this->evaluator->evaluate($row, $hasEnrollment, $hasPaymentData, $accountNumber, $rfc);
    }

    /** @test */
    public function all_clear_row_is_payable_with_no_blocking_reasons(): void
    {
        $result = $this->evaluate($this->makeRow(), hasEnrollment: true, hasPaymentData: true);

        $this->assertTrue($result['is_payable']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    /** @test */
    public function not_approved_when_workflow_status_is_not_listo_para_pago(): void
    {
        $row = $this->makeRow(['workflow_status' => 'PENDIENTE_NOTIFICACION']);

        $result = $this->evaluate($row, hasEnrollment: true, hasPaymentData: true);

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            [['code' => 'NOT_APPROVED', 'message' => 'Pendiente de aprobación']],
            $result['blocking_reasons']
        );
    }

    /** @test */
    public function already_paid_when_locked_at_is_set(): void
    {
        $row = $this->makeRow(['locked_at' => '2026-09-01 10:00:00']);

        $result = $this->evaluate($row, hasEnrollment: true, hasPaymentData: true);

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            [['code' => 'ALREADY_PAID', 'message' => 'Ya fue procesado en un pago anterior']],
            $result['blocking_reasons']
        );
    }

    /** @test */
    public function already_paid_when_payment_batch_id_is_set(): void
    {
        $row = $this->makeRow(['payment_batch_id' => 7]);

        $result = $this->evaluate($row, hasEnrollment: true, hasPaymentData: true);

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            [['code' => 'ALREADY_PAID', 'message' => 'Ya fue procesado en un pago anterior']],
            $result['blocking_reasons']
        );
    }

    /** @test */
    public function missing_enrollment_when_has_enrollment_is_false(): void
    {
        $result = $this->evaluate($this->makeRow(), hasEnrollment: false, hasPaymentData: true);

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            [['code' => 'MISSING_ENROLLMENT', 'message' => 'Sin matrícula registrada']],
            $result['blocking_reasons']
        );
    }

    /** @test */
    public function missing_payment_data_when_has_payment_data_is_false(): void
    {
        $result = $this->evaluate($this->makeRow(), hasEnrollment: true, hasPaymentData: false);

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            [['code' => 'MISSING_PAYMENT_DATA', 'message' => 'Sin cuenta bancaria registrada']],
            $result['blocking_reasons']
        );
    }

    /** @test */
    public function nothing_to_pay_when_withheld_and_final_amount_is_zero(): void
    {
        $row = $this->makeRow(['status' => 'WITHHELD', 'final_amount' => 0]);

        $result = $this->evaluate($row, hasEnrollment: true, hasPaymentData: true);

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            [['code' => 'NOTHING_TO_PAY', 'message' => 'Monto retenido sin saldo por pagar']],
            $result['blocking_reasons']
        );
    }

    /** @test */
    public function nothing_to_pay_when_withheld_and_final_amount_is_negative(): void
    {
        $row = $this->makeRow(['status' => 'WITHHELD', 'final_amount' => -50]);

        $result = $this->evaluate($row, hasEnrollment: true, hasPaymentData: true);

        $this->assertFalse($result['is_payable']);
        $this->assertSame('NOTHING_TO_PAY', $result['blocking_reasons'][0]['code']);
    }

    /** @test */
    public function withheld_with_positive_final_amount_is_not_blocked_by_nothing_to_pay(): void
    {
        $row = $this->makeRow(['status' => 'WITHHELD', 'final_amount' => 250.00]);

        $result = $this->evaluate($row, hasEnrollment: true, hasPaymentData: true);

        $this->assertTrue($result['is_payable']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    /** @test */
    public function multiple_simultaneous_blocking_reasons_are_all_returned(): void
    {
        // Missing enrollment AND missing payment data at the same time.
        $result = $this->evaluate($this->makeRow(), hasEnrollment: false, hasPaymentData: false);

        $this->assertFalse($result['is_payable']);
        $this->assertCount(2, $result['blocking_reasons']);
        $this->assertSame(
            ['MISSING_ENROLLMENT', 'MISSING_PAYMENT_DATA'],
            array_column($result['blocking_reasons'], 'code')
        );
    }

    /** @test */
    public function all_five_blocking_reasons_can_fire_at_once(): void
    {
        $row = $this->makeRow([
            'workflow_status'  => 'PENDIENTE_NOTIFICACION',
            'locked_at'        => '2026-09-01 10:00:00',
            'status'           => 'WITHHELD',
            'final_amount'     => 0,
        ]);

        $result = $this->evaluate($row, hasEnrollment: false, hasPaymentData: false);

        $this->assertFalse($result['is_payable']);
        $this->assertCount(5, $result['blocking_reasons']);
        $this->assertSame(
            ['NOT_APPROVED', 'ALREADY_PAID', 'MISSING_ENROLLMENT', 'MISSING_PAYMENT_DATA', 'NOTHING_TO_PAY'],
            array_column($result['blocking_reasons'], 'code')
        );
    }

    // ── Bank data rules (PR3, delegated to BankDataValidator) ──────────────

    /** @test */
    public function malformed_account_number_blocks_an_otherwise_clean_row(): void
    {
        $result = $this->evaluate($this->makeRow(), hasEnrollment: true, hasPaymentData: true, accountNumber: '12345678A');

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            [['code' => 'INVALID_ACCOUNT_NUMBER', 'message' => 'Número de cuenta inválido: debe ser numérico de 9 o 10 dígitos']],
            $result['blocking_reasons']
        );
    }

    /** @test */
    public function null_account_number_blocks_an_otherwise_clean_row(): void
    {
        $result = $this->evaluate($this->makeRow(), hasEnrollment: true, hasPaymentData: true, accountNumber: null);

        $this->assertFalse($result['is_payable']);
        $this->assertSame('INVALID_ACCOUNT_NUMBER', $result['blocking_reasons'][0]['code']);
    }

    /** @test */
    public function malformed_rfc_blocks_an_otherwise_clean_row(): void
    {
        $result = $this->evaluate($this->makeRow(), hasEnrollment: true, hasPaymentData: true, rfc: '1234800101ABC');

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            [['code' => 'INVALID_RFC', 'message' => 'RFC con estructura inválida']],
            $result['blocking_reasons']
        );
    }

    /** @test */
    public function blank_rfc_still_passes_matching_bank_data_validators_behavior(): void
    {
        $result = $this->evaluate($this->makeRow(), hasEnrollment: true, hasPaymentData: true, rfc: null);

        $this->assertTrue($result['is_payable']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    /** @test */
    public function well_formed_account_and_rfc_do_not_block_an_otherwise_clean_row(): void
    {
        $result = $this->evaluate(
            $this->makeRow(),
            hasEnrollment: true,
            hasPaymentData: true,
            accountNumber: '0123456789',
            rfc: 'PEPJ800101ABC'
        );

        $this->assertTrue($result['is_payable']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    /** @test */
    public function bank_data_reasons_do_not_duplicate_the_withheld_nothing_to_pay_code(): void
    {
        // WITHHELD + final_amount<=0 already fires NOTHING_TO_PAY via the
        // evaluator's own rule; BankDataValidator's blanket amount>0 rule is
        // intentionally not merged in (it would require duplicating
        // PaymentBatchService::totalToPay()'s formula, which the design
        // forbids) — so exactly ONE NOTHING_TO_PAY reason must appear, not two.
        $row = $this->makeRow(['status' => 'WITHHELD', 'final_amount' => 0]);

        $result = $this->evaluate($row, hasEnrollment: true, hasPaymentData: true);

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            ['NOTHING_TO_PAY'],
            array_column($result['blocking_reasons'], 'code')
        );
    }

    /** @test */
    public function malformed_account_number_combines_with_other_blocking_reasons(): void
    {
        $row = $this->makeRow(['workflow_status' => 'PENDIENTE_NOTIFICACION']);

        $result = $this->evaluate($row, hasEnrollment: true, hasPaymentData: true, accountNumber: 'BAD');

        $this->assertFalse($result['is_payable']);
        $this->assertSame(
            ['NOT_APPROVED', 'INVALID_ACCOUNT_NUMBER'],
            array_column($result['blocking_reasons'], 'code')
        );
    }
}
