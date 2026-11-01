<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentMethod;
use PHPUnit\Framework\TestCase;

class PaymentMethodTest extends TestCase
{
    /** @test */
    public function it_exposes_all_values_including_snapshot_only_manual_transfer(): void
    {
        $this->assertSame('TRANSFER', PaymentMethod::TRANSFER->value);
        $this->assertSame('CHEQUE', PaymentMethod::CHEQUE->value);
        $this->assertSame(['TRANSFER', 'CHEQUE', 'MANUAL_TRANSFER'], PaymentMethod::values());
    }

    /** @test */
    public function it_resolves_from_string_and_rejects_unknown(): void
    {
        $this->assertSame(PaymentMethod::CHEQUE, PaymentMethod::tryFrom('CHEQUE'));
        $this->assertNull(PaymentMethod::tryFrom('CASH'));
    }
}
