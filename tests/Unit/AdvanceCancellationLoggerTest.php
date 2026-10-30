<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\User;
use App\Services\Scholarship\AdvanceCancellationLogger;
use App\Services\Scholarship\AdvanceCycleCutCanceller;
use App\Services\Scholarship\CutAnchor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/retenciones-pendientes, PR2: the origin audit log of cancelled advance
 * payments, extracted from CloseScholarshipCycleAction so the manual cut, the
 * automatic egreso and the user deactivation share it.
 */
class AdvanceCancellationLoggerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public function labels(): array
    {
        return [
            'egresado'     => ['EGRESADO', 'EGRESO'],
            'baja'         => ['BAJA_DEFINITIVA', 'BAJA DEFINITIVA'],
            'egreso retic' => ['EGRESO_RETICULA', 'EGRESO POR RETÍCULA'],
            'deactivation' => ['USER_DEACTIVATED', 'DESACTIVACIÓN DE USUARIO'],
        ];
    }

    /**
     * @test
     * @dataProvider labels
     */
    public function it_maps_each_reason_to_its_human_label(string $reason, string $label): void
    {
        $this->assertSame($label, AdvanceCancellationLogger::label($reason));
    }

    private function refrend(int $userId, int $month, array $extra = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id' => $userId, 'period_year' => 2026, 'period_month' => $month,
            'status' => 'DRAFT', 'workflow_status' => 'DRAFT', 'base_amount' => 1000,
            'discount_percentage' => 0, 'discount_amount' => 0, 'final_amount' => 1000,
            'amount_pending_from_previous' => 0, 'snapshot_name' => 'T',
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ], $extra));
    }

    /** @test */
    public function it_logs_one_entry_per_origin_with_the_reason_label_in_the_note(): void
    {
        $actor  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($actor);
        $user   = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $origin = $this->refrend($user->id, 3, ['advance_payment_amount' => '2000.00']);
        $header = ScholarshipAdvancePayment::create([
            'user_id' => $user->id, 'origin_refrend_id' => $origin->id, 'origin_period_year' => 2026,
            'origin_period_month' => 3, 'months_count' => 2, 'total_amount' => '2000.00',
            'status' => ScholarshipAdvancePayment::STATUS_ACTIVE,
        ]);
        $cut = null;
        foreach ([4, 5] as $m) {
            $r = $this->refrend($user->id, $m, ['created_via' => 'ADVANCE_PAYMENT']);
            $cut ??= $r;
            ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id, 'user_id' => $user->id, 'period_year' => 2026,
                'period_month' => $m, 'amount' => '1000.00', 'refrend_id' => $r->id,
                'status' => ScholarshipAdvancePaymentMonth::STATUS_PENDING,
            ]);
        }

        $anchor = CutAnchor::fromRefrend($cut);
        $plan   = $this->app->make(AdvanceCycleCutCanceller::class)->cancelAfterCut($anchor, 'EGRESO_RETICULA', null);

        $this->app->make(AdvanceCancellationLogger::class)->log($anchor, $plan, 'EGRESO_RETICULA');

        $logs = ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->get();
        $this->assertCount(1, $logs);
        $log = $logs->first();
        $this->assertSame($origin->id, (int) $log->scholarship_refrend_id);
        $this->assertSame('Pago adelantado cancelado por EGRESO POR RETÍCULA del 04/2026.', $log->notes);
        $this->assertSame('2000.00', $log->new_values['cancelled_amount']);
        $this->assertSame('EGRESO_RETICULA', $log->new_values['cut_reason']);
        $this->assertSame($cut->id, $log->new_values['cut_refrend_id']);
        $this->assertSame('2000.00', $log->old_values['advance_payment_amount']);
        $this->assertCount(2, $log->new_values['months']);
    }

    /** @test */
    public function it_logs_nothing_for_an_empty_plan(): void
    {
        $user   = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $anchor = new CutAnchor($user->id, 2026, 4, null);

        $this->app->make(AdvanceCancellationLogger::class)->log($anchor, \App\Services\Scholarship\AdvanceCutPlan::empty(), 'USER_DEACTIVATED');

        $this->assertSame(0, ScholarshipRefrendLog::count());
    }
}
