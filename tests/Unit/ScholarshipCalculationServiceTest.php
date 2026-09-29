<?php

namespace Tests\Unit;

use App\Enums\DiscountType;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScholarshipCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScholarshipCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ScholarshipCalculationService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeProfile(array $overrides = []): ScholarshipProfile
    {
        $user = \App\Models\User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipProfile::create(array_merge([
            'user_id'                    => $user->id,
            'scholarship_type'           => ScholarshipType::IU->value,
            'monthly_amount'             => 2500.00,
            'active_discount_percentage' => 15.00,
            'discount_reason'            => null,
            // Both bounds required since isDiscountActiveOn() uses the shared
            // inclusive rangeCoversDate() primitive (design D1/D3) — a null
            // discount_valid_from now deactivates the discount, so every
            // fixture that expects an ACTIVE discount needs a from in the past.
            'discount_valid_from'        => Carbon::yesterday()->toDateString(),
            'discount_valid_until'       => Carbon::tomorrow()->toDateString(),
            // payment_start_date column still exists on SQLite (dropColumn skipped)
            'payment_start_date'         => now()->toDateString(),
        ], $overrides));
    }

    private function makeRefrend(int $userId): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => 6,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'base_amount'                  => 2500.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 2500.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);
    }

    // ── S-DESC-01: discount_reason non-null ───────────────────────────────────

    /** @test */
    public function discount_description_includes_reason_when_profile_has_discount_reason(): void
    {
        $profile = $this->makeProfile([
            'discount_reason'      => 'Promedio semestral bajo',
            'discount_valid_until' => Carbon::tomorrow()->toDateString(),
        ]);
        $refrend = $this->makeRefrend($profile->user_id);

        $discount = $this->service->applyAcademicDiscount($refrend, $profile);

        $this->assertNotNull($discount);
        $this->assertStringContainsString('Promedio semestral bajo', $discount->description);
        $this->assertStringContainsString('Descuento por promedio académico vigente.', $discount->description);
        $this->assertSame(DiscountType::PROMEDIO_BAJO->value, $discount->discount_type);
    }

    // ── S-DESC-02: discount_reason null ──────────────────────────────────────

    /** @test */
    public function discount_description_uses_fallback_when_discount_reason_is_null(): void
    {
        $profile = $this->makeProfile([
            'discount_reason'      => null,
            'discount_valid_until' => Carbon::tomorrow()->toDateString(),
        ]);
        $refrend = $this->makeRefrend($profile->user_id);

        $discount = $this->service->applyAcademicDiscount($refrend, $profile);

        $this->assertNotNull($discount);
        $this->assertSame('Descuento por promedio académico vigente.', $discount->description);
    }

    // ── S-DESC-03: expired discount_valid_until ───────────────────────────────

    /** @test */
    public function no_discount_created_when_discount_valid_until_is_expired(): void
    {
        $profile = $this->makeProfile([
            'discount_reason'      => 'Baja calificación',
            'discount_valid_until' => Carbon::yesterday()->toDateString(),
        ]);
        $refrend = $this->makeRefrend($profile->user_id);

        $discount = $this->service->applyAcademicDiscount($refrend, $profile);

        $this->assertNull($discount);
    }

    // ── S-DESC-04: active_discount_percentage set but discount_valid_until null ──
    //
    // Regression guard: a discount is a TEMPORARY retention. Historically, a
    // null discount_valid_until was treated as "no expiry" (always active)
    // instead of invalid/inactive data — this must never apply indefinitely.

    /** @test */
    public function no_discount_created_when_discount_valid_until_is_null(): void
    {
        $profile = $this->makeProfile([
            'discount_reason'      => 'Baja calificación',
            'discount_valid_until' => null,
        ]);
        $refrend = $this->makeRefrend($profile->user_id);

        $discount = $this->service->applyAcademicDiscount($refrend, $profile);

        $this->assertNull($discount);
    }

    // ── S-DESC-05: buildSnapshot mirrors the same rule ────────────────────────

    /** @test */
    public function snapshot_discount_is_inactive_when_discount_valid_until_is_null(): void
    {
        $profile = $this->makeProfile([
            'discount_reason'      => 'Baja calificación',
            'discount_valid_until' => null,
        ]);

        $snapshot = $this->service->buildSnapshot($profile);

        $this->assertNull($snapshot['snapshot_discount_percentage']);
        $this->assertNull($snapshot['snapshot_discount_reason']);
    }

    /** @test */
    public function snapshot_discount_is_active_when_discount_valid_until_is_in_the_future(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage' => 15.00,
            'discount_reason'            => 'Baja calificación',
            'discount_valid_until'       => Carbon::tomorrow()->toDateString(),
        ]);

        $snapshot = $this->service->buildSnapshot($profile);

        $this->assertSame(15.0, $snapshot['snapshot_discount_percentage']);
        $this->assertSame('Baja calificación', $snapshot['snapshot_discount_reason']);
    }

    // ── discount_valid_from range (S-DESC-06/07) ─────────────────────────────

    /** @test */
    public function discount_is_inactive_when_discount_valid_from_is_in_the_future(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage' => 15.00,
            'discount_valid_from'        => Carbon::tomorrow()->toDateString(),
            'discount_valid_until'       => Carbon::tomorrow()->addDays(10)->toDateString(),
        ]);

        $snapshot = $this->service->buildSnapshot($profile);

        $this->assertNull($snapshot['snapshot_discount_percentage']);
        $this->assertNull($snapshot['snapshot_discount_reason']);
    }

    /** @test */
    public function discount_is_active_when_discount_valid_from_is_a_backfilled_past_date(): void
    {
        // Simulates a row backfilled by the M2 migration: discount_valid_from
        // set to DATE(created_at), i.e. a past date, with an active discount
        // that is still within its original discount_valid_until.
        $profile = $this->makeProfile([
            'active_discount_percentage' => 15.00,
            'discount_valid_from'        => Carbon::today()->subDays(30)->toDateString(),
            'discount_valid_until'       => Carbon::tomorrow()->toDateString(),
        ]);

        $snapshot = $this->service->buildSnapshot($profile);

        $this->assertSame(15.0, $snapshot['snapshot_discount_percentage']);
    }

    // ── Temporary increase in buildSnapshot() ────────────────────────────────

    /** @test */
    public function build_snapshot_sums_active_temporary_increase_into_gross_amount(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage'     => null,
            'discount_valid_from'            => null,
            'discount_valid_until'           => null,
            'monthly_amount'                 => 2000.00,
            'monto_apoyo'                     => 200.00,
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => '2026-09-01',
            'temporary_increase_valid_until' => '2026-09-30',
            'temporary_increase_reason'      => 'Apoyo transporte',
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(2700.0, $snapshot['snapshot_gross_amount']);
        $this->assertSame(2000.0, $snapshot['base_amount']);
        $this->assertSame(500.0, $snapshot['snapshot_temporary_increase_amount']);
        $this->assertSame('Apoyo transporte', $snapshot['snapshot_temporary_increase_reason']);
    }

    /** @test */
    public function build_snapshot_excludes_expired_temporary_increase(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage'     => null,
            'discount_valid_from'            => null,
            'discount_valid_until'           => null,
            'monthly_amount'                 => 2000.00,
            'monto_apoyo'                     => 0,
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => '2026-08-01',
            'temporary_increase_valid_until' => '2026-08-31',
            'temporary_increase_reason'      => 'Apoyo transporte',
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-01'));

        $this->assertSame(2000.0, $snapshot['snapshot_gross_amount']);
        $this->assertNull($snapshot['snapshot_temporary_increase_amount']);
        $this->assertNull($snapshot['snapshot_temporary_increase_reason']);
    }

    /** @test */
    public function build_snapshot_excludes_future_temporary_increase(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage'     => null,
            'discount_valid_from'            => null,
            'discount_valid_until'           => null,
            'monthly_amount'                 => 2000.00,
            'monto_apoyo'                     => 0,
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => '2026-10-01',
            'temporary_increase_valid_until' => '2026-10-31',
            'temporary_increase_reason'      => 'Apoyo transporte',
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(2000.0, $snapshot['snapshot_gross_amount']);
        $this->assertNull($snapshot['snapshot_temporary_increase_amount']);
        $this->assertNull($snapshot['snapshot_temporary_increase_reason']);
    }

    /** @test */
    public function build_snapshot_without_temporary_increase_matches_previous_behavior(): void
    {
        // Regression guard: a profile with no temporary increase configured at
        // all (all 4 columns null) must calculate exactly as it did before
        // this feature existed.
        $profile = $this->makeProfile([
            'active_discount_percentage'     => null,
            'discount_valid_from'            => null,
            'discount_valid_until'           => null,
            'monthly_amount'                 => 2000.00,
            'monto_apoyo'                     => 200.00,
            'temporary_increase_amount'      => null,
            'temporary_increase_valid_from'  => null,
            'temporary_increase_valid_until' => null,
            'temporary_increase_reason'      => null,
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(2200.0, $snapshot['snapshot_gross_amount']);
        $this->assertSame(2000.0, $snapshot['base_amount']);
        $this->assertNull($snapshot['snapshot_temporary_increase_amount']);
        $this->assertNull($snapshot['snapshot_temporary_increase_reason']);
    }

    /** @test */
    public function academic_discount_is_applied_over_gross_amount_including_temporary_increase(): void
    {
        // Spec scenario: monthly_amount=2000, monto_apoyo=200, aumento
        // vigente=500, descuento activo=10% -> snapshot_gross_amount=2700 and
        // calculateFinalAmount() applies the 10% over 2700 (not over 2200).
        $profile = $this->makeProfile([
            'monthly_amount'                  => 2000.00,
            'monto_apoyo'                      => 200.00,
            'active_discount_percentage'      => 10.00,
            'discount_valid_from'             => Carbon::parse('2026-09-01'),
            'discount_valid_until'            => Carbon::parse('2026-09-30'),
            'temporary_increase_amount'       => 500.00,
            'temporary_increase_valid_from'   => '2026-09-01',
            'temporary_increase_valid_until'  => '2026-09-30',
            'temporary_increase_reason'       => 'Apoyo transporte',
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(2700.0, $snapshot['snapshot_gross_amount']);
        $this->assertSame(10.0, $snapshot['snapshot_discount_percentage']);

        $refrend = $this->makeRefrend($profile->user_id);
        $refrend->update([
            'snapshot_gross_amount'        => $snapshot['snapshot_gross_amount'],
            'snapshot_discount_percentage' => $snapshot['snapshot_discount_percentage'],
        ]);

        $result = $this->service->calculateFinalAmount($refrend->fresh());

        $this->assertSame(2430.0, $result['final_amount']);
    }

    // ── sdd/scholarship-telmex-iu-split, design D2: type-conditional gross composition ──

    /** @test */
    public function iu_snapshot_is_byte_identical_to_the_pre_change_formula(): void
    {
        // Regression guard (spec "IU is byte-identical"): monthly_amount,
        // monto_apoyo, and an active increase — same numbers as the
        // pre-existing test above, asserted again explicitly for the IU
        // branch of the new match().
        $profile = $this->makeProfile([
            'scholarship_type'                => ScholarshipType::IU->value,
            'active_discount_percentage'      => null,
            'discount_valid_from'             => null,
            'discount_valid_until'            => null,
            'monthly_amount'                  => 2000.00,
            'monto_apoyo'                      => 200.00,
            'temporary_increase_amount'       => 500.00,
            'temporary_increase_valid_from'   => '2026-09-01',
            'temporary_increase_valid_until'  => '2026-09-30',
            'temporary_increase_reason'       => 'Apoyo transporte',
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(2700.0, $snapshot['snapshot_gross_amount']);
        $this->assertSame(2000.0, $snapshot['base_amount']);
        $this->assertNull($snapshot['snapshot_telmex_covered_amount']);
    }

    /** @test */
    public function pure_telmex_with_no_active_increase_has_zero_gross_and_covered_bookkeeping(): void
    {
        $profile = $this->makeProfile([
            'scholarship_type'                => ScholarshipType::TELMEX->value,
            'active_discount_percentage'      => null,
            'discount_valid_from'             => null,
            'discount_valid_until'            => null,
            'monthly_amount'                  => 1800.00,
            'monto_apoyo'                      => 150.00,
            'temporary_increase_amount'       => null,
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(0.0, $snapshot['snapshot_gross_amount']);
        $this->assertSame(1950.0, $snapshot['snapshot_telmex_covered_amount']);
        $this->assertSame(0.0, $snapshot['base_amount']);
    }

    /** @test */
    public function pure_telmex_with_an_active_increase_is_increase_only_never_monthly_or_apoyo(): void
    {
        $profile = $this->makeProfile([
            'scholarship_type'                => ScholarshipType::TELMEX->value,
            'active_discount_percentage'      => null,
            'discount_valid_from'             => null,
            'discount_valid_until'            => null,
            'monthly_amount'                  => 1800.00,
            'monto_apoyo'                      => 150.00,
            'temporary_increase_amount'       => 500.00,
            'temporary_increase_valid_from'   => '2026-09-01',
            'temporary_increase_valid_until'  => '2026-09-30',
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(500.0, $snapshot['snapshot_gross_amount']);
        $this->assertSame(1950.0, $snapshot['snapshot_telmex_covered_amount']);
        $this->assertSame(0.0, $snapshot['base_amount']);
    }

    /** @test */
    public function telmex_iu_with_no_active_increase_uses_iu_payment_amount_only(): void
    {
        $profile = $this->makeProfile([
            'scholarship_type'                => ScholarshipType::TELMEX_IU->value,
            'active_discount_percentage'      => null,
            'discount_valid_from'             => null,
            'discount_valid_until'            => null,
            'monthly_amount'                  => 1800.00,
            'monto_apoyo'                      => 150.00,
            'iu_payment_amount'               => 300.00,
            'temporary_increase_amount'       => null,
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(300.0, $snapshot['snapshot_gross_amount']);
        $this->assertSame(1950.0, $snapshot['snapshot_telmex_covered_amount']);
        $this->assertSame(300.0, $snapshot['base_amount']);
    }

    /** @test */
    public function telmex_iu_with_an_active_increase_sums_iu_payment_and_increase(): void
    {
        $profile = $this->makeProfile([
            'scholarship_type'                => ScholarshipType::TELMEX_IU->value,
            'active_discount_percentage'      => null,
            'discount_valid_from'             => null,
            'discount_valid_until'            => null,
            'monthly_amount'                  => 1800.00,
            'monto_apoyo'                      => 150.00,
            'iu_payment_amount'               => 300.00,
            'temporary_increase_amount'       => 500.00,
            'temporary_increase_valid_from'   => '2026-09-01',
            'temporary_increase_valid_until'  => '2026-09-30',
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(800.0, $snapshot['snapshot_gross_amount']);
        $this->assertSame(1950.0, $snapshot['snapshot_telmex_covered_amount']);
        $this->assertSame(300.0, $snapshot['base_amount']);
    }

    /** @test */
    public function telmex_iu_discount_applies_only_to_the_iu_sourced_gross_never_to_covered_amount(): void
    {
        // Spec scenario: TELMEX_IU discount applies only to the payable
        // gross (Pago IU + increase = 800), snapshot_telmex_covered_amount
        // (monthly_amount + monto_apoyo) is untouched by the discount engine
        // — discounts/retentions read only snapshot_gross_amount.
        $profile = $this->makeProfile([
            'scholarship_type'                => ScholarshipType::TELMEX_IU->value,
            'active_discount_percentage'      => 100.00,
            'discount_valid_from'             => Carbon::parse('2026-09-01'),
            'discount_valid_until'            => Carbon::parse('2026-09-30'),
            'monthly_amount'                  => 1800.00,
            'monto_apoyo'                      => 150.00,
            'iu_payment_amount'               => 300.00,
            'temporary_increase_amount'       => 500.00,
            'temporary_increase_valid_from'   => '2026-09-01',
            'temporary_increase_valid_until'  => '2026-09-30',
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(800.0, $snapshot['snapshot_gross_amount']);
        $this->assertSame(1950.0, $snapshot['snapshot_telmex_covered_amount']);
        $this->assertSame(100.0, $snapshot['snapshot_discount_percentage']);

        $refrend = $this->makeRefrend($profile->user_id);
        $refrend->update([
            'snapshot_gross_amount'        => $snapshot['snapshot_gross_amount'],
            'snapshot_discount_percentage' => $snapshot['snapshot_discount_percentage'],
        ]);

        $result = $this->service->calculateFinalAmount($refrend->fresh());

        // max(0, $finalAmount) (calculateFinalAmount()'s existing clamp)
        // returns the int literal 0 when clamped, not a float — assertEquals
        // (loose) matches this file's convention for the zero case.
        $this->assertEquals(0, $result['final_amount']);
    }

    // ── D6: base_amount is type-aware (phantom-debt fix) ────────────────────

    /** @test */
    public function base_amount_is_zero_for_pure_telmex_never_monthly_amount(): void
    {
        // The bug this fixes: leaving base_amount = monthly_amount for a
        // pure TELMEX profile would let BackfillWithholdingLedgerService
        // record never-payable money as withheld debt.
        $profile = $this->makeProfile([
            'scholarship_type'                => ScholarshipType::TELMEX->value,
            'active_discount_percentage'      => null,
            'discount_valid_from'             => null,
            'discount_valid_until'            => null,
            'monthly_amount'                  => 2500.00,
            'monto_apoyo'                      => 300.00,
            'temporary_increase_amount'       => null,
        ]);

        $snapshot = $this->service->buildSnapshot($profile, Carbon::parse('2026-09-15'));

        $this->assertSame(0.0, $snapshot['base_amount']);
    }
}
