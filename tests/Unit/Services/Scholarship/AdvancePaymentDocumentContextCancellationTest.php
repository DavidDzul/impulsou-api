<?php

namespace Tests\Unit\Services\Scholarship;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\AdvancePaymentDocumentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S7 (spec R19 / S39-S41): the document
 * context exposes the cycle-cut cancellation data so administration-panel can
 * render "Sobrepago por adelanto" and a cancelled branch.
 */
class AdvancePaymentDocumentContextCancellationTest extends TestCase
{
    use RefreshDatabase;

    private AdvancePaymentDocumentContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new AdvancePaymentDocumentContext();
    }

    private function makeRefrend(int $userId, int $month): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => $month,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);
    }

    /**
     * March origin advancing April and May at 2,500.00 each.
     *
     * @param array<string, mixed> $headerOverrides
     * @return array{origin: ScholarshipRefrend, april: ScholarshipRefrend, may: ScholarshipRefrend, header: ScholarshipAdvancePayment}
     */
    private function makeAdvance(array $headerOverrides = [], string $monthStatus = 'PENDING'): array
    {
        $user   = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);
        $origin = $this->makeRefrend($user->id, 3);
        $april  = $this->makeRefrend($user->id, 4);
        $may    = $this->makeRefrend($user->id, 5);

        $header = ScholarshipAdvancePayment::create(array_merge([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 3,
            'months_count'        => 2,
            'total_amount'        => '5000.00',
            'status'              => 'ACTIVE',
        ], $headerOverrides));

        foreach ([[4, $april], [5, $may]] as [$month, $refrend]) {
            ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id,
                'user_id'            => $user->id,
                'period_year'        => 2026,
                'period_month'       => $month,
                'amount'             => '2500.00',
                'refrend_id'         => $refrend->id,
                'status'             => $monthStatus,
            ]);
        }

        return compact('origin', 'april', 'may', 'header');
    }

    /** @test */
    public function an_active_advance_exposes_inert_cancellation_fields(): void
    {
        $f      = $this->makeAdvance();
        $result = $this->context->forRefrend($f['origin']);

        $this->assertTrue($result['has_registered_batch']);
        $this->assertSame('ACTIVE', $result['registered_status']);
        $this->assertNull($result['registered_cancelled_at']);
        $this->assertNull($result['registered_cancel_reason']);
        $this->assertNull($result['registered_cancelled_cut_refrend']);
        $this->assertSame('0.00', $result['registered_cancelled_amount']);
        $this->assertSame('0.00', $result['registered_overpaid_amount']);
        // Pre-existing keys are untouched.
        $this->assertSame(2, $result['registered_months_count']);
        $this->assertSame('5000.00', $result['registered_total_amount']);
    }

    /** @test */
    public function a_refrend_without_a_batch_has_null_cancellation_fields(): void
    {
        $f      = $this->makeAdvance();
        $result = $this->context->forRefrend($f['may']);

        $this->assertFalse($result['has_registered_batch']);
        $this->assertNull($result['registered_status']);
        $this->assertNull($result['registered_cancelled_at']);
        $this->assertNull($result['registered_cancel_reason']);
        $this->assertNull($result['registered_cancelled_cut_refrend']);
        $this->assertNull($result['registered_cancelled_amount']);
        $this->assertNull($result['registered_overpaid_amount']);
    }

    /** @test */
    public function a_cancelled_header_exposes_the_cancellation_and_overpayment(): void
    {
        $f = $this->makeAdvance(['status' => 'CANCELLED'], 'CANCELLED');
        $f['header']->update([
            'cancelled_at'             => '2026-04-10 09:30:00',
            'cancel_reason'            => 'BAJA_DEFINITIVA',
            'cancelled_cut_refrend_id' => $f['april']->id,
            'cancelled_amount'         => '5000.00',
            'overpaid_amount'          => '5000.00',
        ]);

        $result = $this->context->forRefrend($f['origin']);

        $this->assertSame('CANCELLED', $result['registered_status']);
        $this->assertSame(
            \Illuminate\Support\Carbon::parse('2026-04-10 09:30:00')->toIso8601String(),
            $result['registered_cancelled_at']
        );
        $this->assertSame('BAJA_DEFINITIVA', $result['registered_cancel_reason']);
        $this->assertSame(
            ['id' => $f['april']->id, 'period_year' => 2026, 'period_month' => 4],
            $result['registered_cancelled_cut_refrend']
        );
        $this->assertSame('5000.00', $result['registered_cancelled_amount']);
        $this->assertSame('5000.00', $result['registered_overpaid_amount']);
    }

    /** @test */
    public function a_cancelled_month_reports_status_cancelled_and_its_header_cancellation(): void
    {
        $f = $this->makeAdvance(['status' => 'CANCELLED'], 'CANCELLED');
        $f['header']->update([
            'cancelled_at'             => '2026-04-10 09:30:00',
            'cancel_reason'            => 'EGRESADO',
            'cancelled_cut_refrend_id' => $f['april']->id,
            'cancelled_amount'         => '5000.00',
            'overpaid_amount'          => '0.00',
        ]);

        $result = $this->context->forRefrend($f['may']);

        $this->assertTrue($result['settled_as_advance']);
        $this->assertSame('CANCELLED', $result['settled_status']);
        $this->assertNull($result['settled_resolution_type']);
        $this->assertNull($result['divergence_reason']);
        $this->assertSame('CANCELLED', $result['settled_header_status']);
        $this->assertSame('EGRESADO', $result['settled_cancel_reason']);
        $this->assertNotNull($result['settled_cancelled_at']);
        $this->assertSame(
            ['id' => $f['april']->id, 'period_year' => 2026, 'period_month' => 4],
            $result['settled_cancelled_cut_refrend']
        );
    }

    /** @test */
    public function a_partially_cancelled_header_keeps_its_status(): void
    {
        $f = $this->makeAdvance(['status' => 'PARTIALLY_CANCELLED']);
        $f['header']->update(['cancelled_amount' => '2500.00', 'overpaid_amount' => '0.00']);

        $result = $this->context->forRefrend($f['origin']);

        $this->assertSame('PARTIALLY_CANCELLED', $result['registered_status']);
        $this->assertSame('2500.00', $result['registered_cancelled_amount']);
    }

    /** @test */
    public function an_active_settled_month_has_inert_header_cancellation_fields(): void
    {
        $f      = $this->makeAdvance();
        $result = $this->context->forRefrend($f['may']);

        $this->assertSame('PENDING', $result['settled_status']);
        $this->assertSame('ACTIVE', $result['settled_header_status']);
        $this->assertNull($result['settled_cancelled_at']);
        $this->assertNull($result['settled_cancel_reason']);
        $this->assertNull($result['settled_cancelled_cut_refrend']);
    }
}
