<?php

namespace Tests\Unit;

use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScholarshipAdvancePaymentCancellationModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_exposes_the_status_constants(): void
    {
        $this->assertSame('ACTIVE', ScholarshipAdvancePayment::STATUS_ACTIVE);
        $this->assertSame('PARTIALLY_CANCELLED', ScholarshipAdvancePayment::STATUS_PARTIALLY_CANCELLED);
        $this->assertSame('CANCELLED', ScholarshipAdvancePayment::STATUS_CANCELLED);

        $this->assertSame('PENDING', ScholarshipAdvancePaymentMonth::STATUS_PENDING);
        $this->assertSame('REACHED', ScholarshipAdvancePaymentMonth::STATUS_REACHED);
        $this->assertSame('OVERRIDDEN', ScholarshipAdvancePaymentMonth::STATUS_OVERRIDDEN);
        $this->assertSame('CANCELLED', ScholarshipAdvancePaymentMonth::STATUS_CANCELLED);
    }

    /** @test */
    public function it_casts_and_relates_the_cancellation_fields(): void
    {
        $user    = User::factory()->create();
        $admin   = User::factory()->create();
        $origin  = $this->refrend($user, 9);
        $cut     = $this->refrend($user, 10);

        $header = ScholarshipAdvancePayment::create([
            'user_id'                  => $user->id,
            'origin_refrend_id'        => $origin->id,
            'origin_period_year'       => 2026,
            'origin_period_month'      => 9,
            'months_count'             => 2,
            'total_amount'             => 2000,
            'status'                   => ScholarshipAdvancePayment::STATUS_CANCELLED,
            'cancelled_at'             => '2026-10-15 10:00:00',
            'cancelled_by_id'          => $admin->id,
            'cancel_reason'            => 'BAJA_DEFINITIVA',
            'cancelled_cut_refrend_id' => $cut->id,
            'cancelled_amount'         => 2000,
            'overpaid_amount'          => 1000.5,
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $header->cancelled_at);
        $this->assertSame('2000.00', $header->cancelled_amount);
        $this->assertSame('1000.50', $header->overpaid_amount);
        $this->assertSame('BAJA_DEFINITIVA', $header->cancel_reason);
        $this->assertTrue($header->cancelledBy->is($admin));
        $this->assertTrue($header->cancelledCutRefrend->is($cut));
    }

    /** @test */
    public function the_active_scope_uses_the_constant(): void
    {
        $user   = User::factory()->create();
        $origin = $this->refrend($user, 9);

        ScholarshipAdvancePayment::create([
            'user_id' => $user->id, 'origin_refrend_id' => $origin->id,
            'origin_period_year' => 2026, 'origin_period_month' => 9,
            'months_count' => 1, 'total_amount' => 1000,
        ]);

        $this->assertSame(1, ScholarshipAdvancePayment::active()->count());
    }

    private function refrend(User $user, int $month): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id' => $user->id, 'period_year' => 2026, 'period_month' => $month,
            'base_amount' => 2000, 'final_amount' => 2000, 'snapshot_name' => 'X',
        ]);
    }
}
