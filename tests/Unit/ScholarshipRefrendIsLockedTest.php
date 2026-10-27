<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Models\ScholarshipRefrend;
use PHPUnit\Framework\TestCase;

class ScholarshipRefrendIsLockedTest extends TestCase
{
    private function refrend(string $status, string $workflow, bool $lockedAt = false): ScholarshipRefrend
    {
        return (new ScholarshipRefrend())->setRawAttributes([
            'status'          => $status,
            'workflow_status' => $workflow,
            'locked_at'       => $lockedAt ? '2026-06-01 10:00:00' : null,
        ]);
    }

    /**
     * @test
     * @dataProvider truthTable
     */
    public function is_locked_depends_only_on_workflow_closed(string $status, string $workflow, bool $lockedAt, bool $expected): void
    {
        $this->assertSame($expected, $this->refrend($status, $workflow, $lockedAt)->isLocked());
    }

    public static function truthTable(): array
    {
        $rows = [];
        foreach (['DRAFT', 'WITHHELD', 'PAID', 'CANCELLED'] as $status) {
            $rows["$status + CLOSED"] = [$status, 'CLOSED', true, true];
            foreach (['DRAFT', 'CON_INCIDENCIA', 'LISTO_PARA_PAGO'] as $workflow) {
                $rows["$status + $workflow"] = [$status, $workflow, false, false];
            }
        }

        return $rows;
    }

    /** @test */
    public function cancelled_and_closed_is_locked(): void
    {
        $this->assertTrue($this->refrend(RefrendStatus::CANCELLED->value, 'CLOSED')->isLocked());
    }

    /** @test */
    public function locked_at_alone_does_not_lock_an_open_workflow(): void
    {
        $this->assertFalse($this->refrend('DRAFT', 'LISTO_PARA_PAGO', true)->isLocked());
    }
}
