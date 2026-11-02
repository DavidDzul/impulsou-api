<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentMethod;
use PHPUnit\Framework\TestCase;

class PaymentMethodManualTransferTest extends TestCase
{
    /** @test */
    public function manual_transfer_is_a_snapshot_only_value(): void
    {
        $this->assertSame('MANUAL_TRANSFER', PaymentMethod::MANUAL_TRANSFER->value);
        $this->assertContains('MANUAL_TRANSFER', PaymentMethod::values());
        $this->assertSame(['TRANSFER', 'CHEQUE'], PaymentMethod::selectableValues());
    }

    /** @test */
    public function only_cheque_and_manual_transfer_are_manual(): void
    {
        $this->assertTrue(PaymentMethod::CHEQUE->isManual());
        $this->assertTrue(PaymentMethod::MANUAL_TRANSFER->isManual());
        $this->assertFalse(PaymentMethod::TRANSFER->isManual());
        $this->assertSame(['CHEQUE', 'MANUAL_TRANSFER'], PaymentMethod::manualValues());
    }

    /** @test */
    public function log_actions_follow_the_method(): void
    {
        $this->assertSame('CHEQUE_PAID', PaymentMethod::CHEQUE->paidLogAction());
        $this->assertSame('CHEQUE_PAYMENT_REVERTED', PaymentMethod::CHEQUE->revertLogAction());
        $this->assertSame('MANUAL_TRANSFER_PAID', PaymentMethod::MANUAL_TRANSFER->paidLogAction());
        $this->assertSame('MANUAL_TRANSFER_REVERTED', PaymentMethod::MANUAL_TRANSFER->revertLogAction());
    }
}
