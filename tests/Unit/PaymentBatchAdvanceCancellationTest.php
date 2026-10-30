<?php

namespace Tests\Unit;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\PaymentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S7: PaymentBatchService must not treat a
 * CANCELLED advance month as advance-paid, and the refrends a cycle cut
 * cancels (CANCELLED/CLOSED $0, resolution BAJA_DEFINITIVA/EGRESADO) must
 * never be batch candidates. Money totals for ACTIVE data stay unchanged.
 */
class PaymentBatchAdvanceCancellationTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;

    private PaymentBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PaymentBatchService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function makeReadyRefrend(User $user, int $month, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => $month,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function makeUser(): User
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => self::CAMPUS, 'active' => true]);

        ScholarshipPaymentData::create([
            'user_id'        => $user->id,
            'bank_name'      => 'BBVA',
            'account_number' => '0123456789',
            'curp'           => 'CURP010101HDFXXX01',
            'rfc'            => 'PEPJ800101ABC',
        ]);

        return $user;
    }

    private function linkAdvanceMonth(User $user, ScholarshipRefrend $origin, ScholarshipRefrend $covered, string $monthStatus, string $headerStatus = 'ACTIVE'): void
    {
        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => $origin->period_year,
            'origin_period_month' => $origin->period_month,
            'months_count'        => 1,
            'total_amount'        => 500.00,
            'status'              => $headerStatus,
        ]);

        ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $header->id,
            'user_id'            => $user->id,
            'period_year'        => $covered->period_year,
            'period_month'       => $covered->period_month,
            'amount'             => 500.00,
            'refrend_id'         => $covered->id,
            'status'             => $monthStatus,
        ]);
    }

    /** @return array<string, mixed> */
    private function rowFor(ScholarshipRefrend $refrend): array
    {
        return collect($this->service->rows(self::CAMPUS, self::YEAR, (int) $refrend->period_month))
            ->firstWhere('refrend_id', $refrend->id);
    }

    /** @test */
    public function a_cancelled_advance_month_is_not_advance_paid(): void
    {
        $user    = $this->makeUser();
        $origin  = $this->makeReadyRefrend($user, 3, ['workflow_status' => 'DRAFT']);
        $covered = $this->makeReadyRefrend($user, 5);
        $this->linkAdvanceMonth($user, $origin, $covered, 'CANCELLED', 'CANCELLED');

        $row = $this->rowFor($covered);

        $this->assertFalse($row['advance_paid']);
        $this->assertNull($row['advance_paid_amount']);
        $this->assertNull($row['advance_paid_origin_year']);
        $this->assertNull($row['advance_paid_origin_month']);
        $this->assertNull($row['advance_paid_divergence_reason']);
    }

    /** @test */
    public function an_active_advance_month_renders_exactly_as_before(): void
    {
        $user    = $this->makeUser();
        $origin  = $this->makeReadyRefrend($user, 3, ['workflow_status' => 'DRAFT']);
        $covered = $this->makeReadyRefrend($user, 5);
        $this->linkAdvanceMonth($user, $origin, $covered, 'PENDING');

        $row = $this->rowFor($covered);

        $this->assertTrue($row['advance_paid']);
        $this->assertSame('500.00', $row['advance_paid_amount']);
        $this->assertSame(self::YEAR, $row['advance_paid_origin_year']);
        $this->assertSame(3, $row['advance_paid_origin_month']);
        $this->assertTrue($row['is_payable']);
        $this->assertSame('1000.00', $row['total_to_pay']);
    }

    /** @test */
    public function refrends_cancelled_by_a_baja_cut_are_never_batch_candidates(): void
    {
        $user    = $this->makeUser();
        $origin  = $this->makeReadyRefrend($user, 3, ['workflow_status' => 'DRAFT']);
        $covered = $this->makeReadyRefrend($user, 5, [
            // Exactly what CloseScholarshipCycleAction::cascadeCloseLaterRefrends writes.
            'resolution_type' => 'BAJA_DEFINITIVA',
            'final_amount'    => '0.00',
            'status'          => RefrendStatus::CANCELLED->value,
            'workflow_status' => 'CLOSED',
            'locked_at'       => now(),
        ]);
        $this->linkAdvanceMonth($user, $origin, $covered, 'CANCELLED', 'CANCELLED');

        $this->assertSame([], $this->service->rows(self::CAMPUS, self::YEAR, 5));
    }

    /** @test */
    public function refrends_cancelled_by_an_egreso_cut_are_never_batch_candidates(): void
    {
        $user    = $this->makeUser();
        $origin  = $this->makeReadyRefrend($user, 3, ['workflow_status' => 'DRAFT']);
        $covered = $this->makeReadyRefrend($user, 5, [
            'resolution_type' => 'EGRESADO',
            'final_amount'    => '0.00',
            'status'          => RefrendStatus::CANCELLED->value,
            'workflow_status' => 'CLOSED',
            'locked_at'       => now(),
        ]);
        $this->linkAdvanceMonth($user, $origin, $covered, 'CANCELLED', 'CANCELLED');

        $this->assertSame([], $this->service->rows(self::CAMPUS, self::YEAR, 5));
    }
}
