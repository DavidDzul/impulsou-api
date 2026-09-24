<?php

namespace Tests\Unit;

use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers tasks 2.4 (Work Unit 2 / PR2) — ScholarshipAdvancePaymentMonth
 * model: relationships to ScholarshipAdvancePayment/ScholarshipRefrend,
 * casts, fillable sanity, and the divergence-tracking fields from design D4
 * (settled_resolution_type, divergence_reason, reached_at).
 */
class ScholarshipAdvancePaymentMonthModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeRefrend(User $user, int $year, int $month): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id'       => $user->id,
            'period_year'   => $year,
            'period_month'  => $month,
            'base_amount'   => 2000,
            'final_amount'  => 2000,
            'snapshot_name' => $user->name ?? 'Test User',
        ]);
    }

    private function makeHeader(User $user, ScholarshipRefrend $originRefrend): ScholarshipAdvancePayment
    {
        return ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $originRefrend->id,
            'origin_period_year'  => $originRefrend->period_year,
            'origin_period_month' => $originRefrend->period_month,
            'months_count'        => 1,
            'total_amount'        => 2000,
        ]);
    }

    /** @test */
    public function it_is_fillable_and_casts_amount_and_reached_at(): void
    {
        $user           = User::factory()->create();
        $originRefrend  = $this->makeRefrend($user, 2026, 9);
        $header         = $this->makeHeader($user, $originRefrend);
        $futureRefrend  = $this->makeRefrend($user, 2027, 6);

        $reachedAt = now();

        $month = ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id'      => $header->id,
            'user_id'                 => $user->id,
            'period_year'             => 2027,
            'period_month'            => 6,
            'amount'                  => '2000.5',
            'refrend_id'              => $futureRefrend->id,
            'status'                  => 'REACHED',
            'reached_at'              => $reachedAt,
            'settled_resolution_type' => 'BECA_MES',
            'divergence_reason'       => null,
        ]);

        $fresh = $month->fresh();

        $this->assertSame('2000.50', $fresh->amount);
        $this->assertSame($reachedAt->toDateTimeString(), $fresh->reached_at->toDateTimeString());
        $this->assertSame('REACHED', $fresh->status);
        $this->assertSame('BECA_MES', $fresh->settled_resolution_type);
    }

    /** @test */
    public function status_defaults_to_pending_on_create(): void
    {
        $user          = User::factory()->create();
        $originRefrend = $this->makeRefrend($user, 2026, 9);
        $header        = $this->makeHeader($user, $originRefrend);
        $futureRefrend = $this->makeRefrend($user, 2027, 6);

        $month = ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $header->id,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 6,
            'amount'             => 2000,
            'refrend_id'         => $futureRefrend->id,
        ]);

        $this->assertSame('PENDING', $month->fresh()->status);
    }

    /** @test */
    public function it_belongs_to_the_advance_payment_and_the_refrend(): void
    {
        $user          = User::factory()->create();
        $originRefrend = $this->makeRefrend($user, 2026, 9);
        $header        = $this->makeHeader($user, $originRefrend);
        $futureRefrend = $this->makeRefrend($user, 2027, 6);

        $month = ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $header->id,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 6,
            'amount'             => 2000,
            'refrend_id'         => $futureRefrend->id,
        ]);

        $this->assertTrue($month->advancePayment->is($header));
        $this->assertTrue($month->refrend->is($futureRefrend));
    }

    /** @test */
    public function pending_scope_only_returns_pending_months(): void
    {
        $user          = User::factory()->create();
        $originRefrend = $this->makeRefrend($user, 2026, 9);
        $header        = $this->makeHeader($user, $originRefrend);

        $pendingRefrend = $this->makeRefrend($user, 2027, 6);
        $reachedRefrend = $this->makeRefrend($user, 2027, 7);

        ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $header->id,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 6,
            'amount'             => 2000,
            'refrend_id'         => $pendingRefrend->id,
        ]);

        ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id'      => $header->id,
            'user_id'                 => $user->id,
            'period_year'             => 2027,
            'period_month'            => 7,
            'amount'                  => 2000,
            'refrend_id'              => $reachedRefrend->id,
            'status'                  => 'REACHED',
            'reached_at'              => now(),
            'settled_resolution_type' => 'BECA_MES',
        ]);

        $pending = ScholarshipAdvancePaymentMonth::pending()->get();

        $this->assertCount(1, $pending);
        $this->assertSame(6, $pending->first()->period_month);
    }
}
