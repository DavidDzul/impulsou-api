<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentBatchType;
use PHPUnit\Framework\TestCase;

class PaymentBatchTypeTest extends TestCase
{
    /** @test */
    public function it_maps_the_cert_unid_flag_to_a_segment(): void
    {
        $this->assertSame(PaymentBatchType::CERT_UNID, PaymentBatchType::forCertUnidFlag(true));
        $this->assertSame(PaymentBatchType::NORMAL, PaymentBatchType::forCertUnidFlag(false));
    }
}
