<?php

namespace Tests\Unit;

use App\Exceptions\ChequePaymentException;
use App\Exceptions\Contracts\CarriesErrorPayload;
use PHPUnit\Framework\TestCase;

/**
 * sdd/pago-cheque PR3 (design D13).
 */
class ChequePaymentExceptionTest extends TestCase
{
    /** @test */
    public function already_paid_is_a_409_with_its_code(): void
    {
        $e = ChequePaymentException::alreadyPaid();

        $this->assertInstanceOf(CarriesErrorPayload::class, $e);
        $this->assertSame('ALREADY_PAID', $e->code());
        $this->assertSame(409, $e->httpStatus());
        $this->assertSame([], $e->payload());
    }

    /** @test */
    public function not_cheque_method_is_a_422(): void
    {
        $e = ChequePaymentException::notChequeMethod();

        $this->assertSame('NOT_CHEQUE_METHOD', $e->code());
        $this->assertSame(422, $e->httpStatus());
    }

    /** @test */
    public function not_payable_carries_the_reasons_and_joins_the_message(): void
    {
        $reasons = [
            ['code' => 'NOT_APPROVED', 'message' => 'Pendiente de aprobación'],
            ['code' => 'MISSING_ENROLLMENT', 'message' => 'Sin matrícula registrada'],
        ];

        $e = ChequePaymentException::notPayable($reasons);

        $this->assertSame('NOT_PAYABLE', $e->code());
        $this->assertSame(422, $e->httpStatus());
        $this->assertSame(['reasons' => $reasons], $e->payload());
        $this->assertStringContainsString('Pendiente de aprobación', $e->getMessage());
    }
}
