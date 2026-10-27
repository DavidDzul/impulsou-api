<?php

namespace Tests\Unit\Enums;

use App\Enums\DiscountType;
use PHPUnit\Framework\TestCase;

class DiscountTypeTest extends TestCase
{
    /** @test */
    public function it_exposes_exactly_the_three_live_types(): void
    {
        $this->assertSame(['RETARDOS', 'FALTA_INJUSTIFICADA', 'PROMEDIO_BAJO'], DiscountType::values());
    }

    /**
     * @test
     * @dataProvider removedTypes
     */
    public function removed_types_do_not_resolve(string $legacy): void
    {
        $this->assertNull(DiscountType::tryFrom($legacy));
    }

    public static function removedTypes(): array
    {
        return [['DOCUMENTOS'], ['RECAUDACION'], ['OTRO']];
    }
}
