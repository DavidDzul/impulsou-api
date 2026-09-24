<?php

namespace Tests\Unit;

use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers tasks 2.4 (Work Unit 2 / PR2) — ScholarshipAdvancePayment model:
 * relationships to User/ScholarshipRefrend/User(createdBy), hasMany(months),
 * casts, and fillable sanity. Mirrors ScholarshipWithholding's style
 * (constants, relations, casts) as the closest existing precedent.
 */
class ScholarshipAdvancePaymentModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeRefrend(User $user, int $year = 2026, int $month = 9): ScholarshipRefrend
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

    /** @test */
    public function it_is_fillable_and_casts_total_amount_to_decimal(): void
    {
        $creator = User::factory()->create();
        $user    = User::factory()->create();
        $refrend = $this->makeRefrend($user);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $refrend->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => 1,
            'total_amount'        => '2000.5',
            'status'              => 'ACTIVE',
            'cause'               => 'Solicitud del becario',
            'notes'               => 'Nota interna',
            'created_by_id'       => $creator->id,
        ]);

        $this->assertSame('2000.50', $header->fresh()->total_amount);
    }

    /** @test */
    public function it_belongs_to_the_user_and_the_creator(): void
    {
        $creator = User::factory()->create();
        $user    = User::factory()->create();
        $refrend = $this->makeRefrend($user);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $refrend->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => 1,
            'total_amount'        => 2000,
            'created_by_id'       => $creator->id,
        ]);

        $this->assertTrue($header->user->is($user));
        $this->assertTrue($header->createdBy->is($creator));
    }

    /** @test */
    public function it_belongs_to_the_origin_refrend(): void
    {
        $user    = User::factory()->create();
        $refrend = $this->makeRefrend($user);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $refrend->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => 1,
            'total_amount'        => 2000,
        ]);

        $this->assertTrue($header->originRefrend->is($refrend));
    }

    /** @test */
    public function it_has_many_months(): void
    {
        $user          = User::factory()->create();
        $originRefrend = $this->makeRefrend($user, 2026, 9);
        $futureRefrend = $this->makeRefrend($user, 2027, 6);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $originRefrend->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => 1,
            'total_amount'        => 2000,
        ]);

        $month = ScholarshipAdvancePaymentMonth::create([
            'advance_payment_id' => $header->id,
            'user_id'            => $user->id,
            'period_year'        => 2027,
            'period_month'       => 6,
            'amount'             => 2000,
            'refrend_id'         => $futureRefrend->id,
        ]);

        $this->assertCount(1, $header->fresh()->months);
        $this->assertTrue($header->months->first()->is($month));
    }
}
