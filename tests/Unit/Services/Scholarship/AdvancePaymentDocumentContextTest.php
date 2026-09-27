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
 * Unit tests for AdvancePaymentDocumentContext — the READ-side assembler for
 * the payment document's advance-payment tab (administration-panel "Ver"
 * modal, added 2026-09-27). Central correctness gate: the two directions
 * (settled_as_advance vs has_registered_batch) come from different rows and
 * must never be conflated, and can both be true for the same refrend.
 */
class AdvancePaymentDocumentContextTest extends TestCase
{
    use RefreshDatabase;

    private AdvancePaymentDocumentContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new AdvancePaymentDocumentContext();
    }

    private function makeBecario(): User
    {
        return User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);
    }

    private function makeRefrend(int $userId, int $year, int $month): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id'                      => $userId,
            'period_year'                  => $year,
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

    // ── Neither direction ─────────────────────────────────────────────────

    /** @test */
    public function a_plain_refrend_has_neither_direction(): void
    {
        $user    = $this->makeBecario();
        $refrend = $this->makeRefrend($user->id, 2026, 9);

        $result = $this->context->forRefrend($refrend);

        $this->assertFalse($result['settled_as_advance']);
        $this->assertFalse($result['has_registered_batch']);
    }

    // ── settled_as_advance direction ─────────────────────────────────────

    /** @test */
    public function reports_settled_as_advance_with_origin_and_pending_status_before_arrival(): void
    {
        $user         = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, 2026, 9);
        $futureRefrend = $this->makeRefrend($user->id, 2027, 6);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $originRefrend->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => 1,
            'total_amount'        => '1000.00',
        ]);
        ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $header->id,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 6,
            'amount'             => '1000.00',
            'refrend_id'         => $futureRefrend->id,
            'status'             => 'PENDING',
        ]);

        $result = $this->context->forRefrend($futureRefrend);

        $this->assertTrue($result['settled_as_advance']);
        $this->assertSame(2026, $result['origin_period_year']);
        $this->assertSame(9, $result['origin_period_month']);
        $this->assertSame('1000.00', $result['settled_amount']);
        $this->assertSame('PENDING', $result['settled_status']);
        $this->assertNull($result['settled_resolution_type']);
        $this->assertNull($result['divergence_reason']);
        $this->assertNull($result['reached_at']);
        $this->assertFalse($result['has_registered_batch']);
    }

    /** @test */
    public function reports_the_divergence_reason_once_the_month_has_been_reconciled_with_a_reason(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, 2026, 9);
        $futureRefrend = $this->makeRefrend($user->id, 2027, 6);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $originRefrend->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => 1,
            'total_amount'        => '1000.00',
        ]);
        ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id'      => $header->id,
            'user_id'                 => $user->id,
            'period_year'             => 2027,
            'period_month'            => 6,
            'amount'                  => '1000.00',
            'refrend_id'              => $futureRefrend->id,
            'status'                  => 'OVERRIDDEN',
            'settled_resolution_type' => 'BECA_MES',
            'divergence_reason'       => 'Autorizado por dirección.',
            'reached_at'              => now(),
        ]);

        $result = $this->context->forRefrend($futureRefrend);

        $this->assertSame('OVERRIDDEN', $result['settled_status']);
        $this->assertSame('BECA_MES', $result['settled_resolution_type']);
        $this->assertSame('Autorizado por dirección.', $result['divergence_reason']);
        $this->assertNotNull($result['reached_at']);
    }

    // ── has_registered_batch direction ───────────────────────────────────

    /** @test */
    public function reports_has_registered_batch_for_an_origin_refrend(): void
    {
        $user          = $this->makeBecario();
        $originRefrend = $this->makeRefrend($user->id, 2026, 9);

        ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $originRefrend->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => 3,
            'total_amount'        => '3600.00',
            'cause'               => 'Solicitud del becario.',
            'notes'               => 'Autorizado por atención.',
        ]);

        $result = $this->context->forRefrend($originRefrend);

        $this->assertTrue($result['has_registered_batch']);
        $this->assertSame(3, $result['registered_months_count']);
        $this->assertSame('3600.00', $result['registered_total_amount']);
        $this->assertSame('Solicitud del becario.', $result['registered_cause']);
        $this->assertSame('Autorizado por atención.', $result['registered_notes']);
        $this->assertFalse($result['settled_as_advance']);
    }

    // ── Both directions at once ───────────────────────────────────────────

    /** @test */
    public function a_refrend_can_be_both_settled_as_advance_and_itself_have_a_registered_batch(): void
    {
        $user           = $this->makeBecario();
        $originRefrendA = $this->makeRefrend($user->id, 2026, 9);
        $middleRefrend  = $this->makeRefrend($user->id, 2027, 3);
        $futureRefrend  = $this->makeRefrend($user->id, 2027, 6);

        $headerA = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $originRefrendA->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => 1,
            'total_amount'        => '1000.00',
        ]);
        ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $headerA->id,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 3,
            'amount'             => '1000.00',
            'refrend_id'         => $middleRefrend->id,
            'status'             => 'PENDING',
        ]);

        // $middleRefrend is ALSO the origin of a second batch, advancing
        // into $futureRefrend.
        ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $middleRefrend->id,
            'origin_period_year'  => 2027,
            'origin_period_month' => 3,
            'months_count'        => 1,
            'total_amount'        => '1000.00',
        ]);

        $result = $this->context->forRefrend($middleRefrend);

        $this->assertTrue($result['settled_as_advance']);
        $this->assertTrue($result['has_registered_batch']);
    }
}
