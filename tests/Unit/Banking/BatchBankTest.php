<?php

namespace Tests\Unit\Banking;

use App\Enums\PaymentMethod;
use App\Support\Banking\BatchBank;
use PHPUnit\Framework\TestCase;

class BatchBankTest extends TestCase
{
    /** @test */
    public function batch_bank_code_is_bbva_banxico_code(): void
    {
        $this->assertSame('012', BatchBank::CODE);
    }

    /**
     * @test
     * @dataProvider channels
     */
    public function it_derives_the_channel(PaymentMethod $method, ?string $bankCode, PaymentMethod $expected): void
    {
        $this->assertSame($expected, BatchBank::channelFor($method, $bankCode));
    }

    public function channels(): array
    {
        return [
            'cheque ignores bank'        => [PaymentMethod::CHEQUE, '072', PaymentMethod::CHEQUE],
            'cheque without bank'        => [PaymentMethod::CHEQUE, null, PaymentMethod::CHEQUE],
            'transfer BBVA'              => [PaymentMethod::TRANSFER, '012', PaymentMethod::TRANSFER],
            'transfer other bank'        => [PaymentMethod::TRANSFER, '072', PaymentMethod::MANUAL_TRANSFER],
            'transfer no bank is blocked' => [PaymentMethod::TRANSFER, null, PaymentMethod::TRANSFER],
        ];
    }
}
