<?php

namespace Tests\Unit\Enums;

use App\Enums\RefrendStatus;
use PHPUnit\Framework\TestCase;

class RefrendStatusTest extends TestCase
{
    /** @test */
    public function it_exposes_exactly_the_four_live_statuses(): void
    {
        $this->assertEqualsCanonicalizing(['DRAFT', 'WITHHELD', 'PAID', 'CANCELLED'], RefrendStatus::values());
    }

    /**
     * @test
     * @dataProvider removedStatuses
     */
    public function removed_legacy_statuses_do_not_resolve(string $legacy): void
    {
        $this->assertNull(RefrendStatus::tryFrom($legacy));
    }

    public static function removedStatuses(): array
    {
        return [['AUTHORIZED'], ['ATENCION_REVIEW'], ['PEDAGOGIA_REVIEW']];
    }

    /** @test */
    public function only_paid_is_locked(): void
    {
        $this->assertTrue(RefrendStatus::PAID->isLocked());
        $this->assertFalse(RefrendStatus::DRAFT->isLocked());
        $this->assertFalse(RefrendStatus::WITHHELD->isLocked());
        $this->assertFalse(RefrendStatus::CANCELLED->isLocked());
    }
}
