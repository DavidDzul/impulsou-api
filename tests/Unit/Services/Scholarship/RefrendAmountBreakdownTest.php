<?php

namespace Tests\Unit\Services\Scholarship;

use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\DiscountType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendDiscount;
use App\Models\TelmexCoverage;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use App\Services\Scholarship\RefrendAmountBreakdown;
use App\Services\ScholarshipCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payment document's "Desglose de monto" must RECONCILE: every line the
 * panel renders adds up to final_amount and total_to_pay. Refrends here are
 * produced by the real generation service / resolution actions (not by
 * hand-setting columns) so the invariant is exercised end to end.
 */
class RefrendAmountBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR  = 2026;
    private const MONTH = 5;

    private GenerateMonthlyRefrendsService $generator;
    private RecordPaymentSituationAction $situation;
    private RefrendAmountBreakdown $breakdown;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(self::YEAR, self::MONTH, 15));

        $this->generator = $this->app->make(GenerateMonthlyRefrendsService::class);
        $this->situation = $this->app->make(RecordPaymentSituationAction::class);
        $this->breakdown = $this->app->make(RefrendAmountBreakdown::class);
        $this->admin     = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function makeProfile(array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipProfile::create(array_merge([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 1000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
            'payment_start_date'  => now()->toDateString(),
            'config_set_at'       => now(),
        ], $overrides));
    }

    private function generate(ScholarshipProfile $profile, int $month = self::MONTH): ScholarshipRefrend
    {
        $refrend = $this->generator->generateForUser($profile, self::YEAR, $month);
        $this->assertNotNull($refrend);

        return $refrend;
    }

    private function withCoverage(ScholarshipProfile $profile, string $type): TelmexCoverage
    {
        return TelmexCoverage::create([
            'user_id'                        => $profile->user_id,
            'scholarship_type_at_activation' => $type,
            'start_period'                   => '2026-01-01',
            'end_period'                     => null,
            'status'                         => 'ACTIVA',
        ]);
    }

    private function academicDiscount(float $pct, string $reason = 'Promedio bajo'): array
    {
        return [
            'active_discount_percentage' => $pct,
            'discount_reason'            => $reason,
            'discount_valid_from'        => '2000-01-01',
            'discount_valid_until'       => '2099-12-31',
        ];
    }

    private function resolve(ScholarshipRefrend $refrend, string $type, array $extra = []): ScholarshipRefrend
    {
        return $this->situation->execute(
            $refrend,
            array_merge(['resolution_type' => $type], $extra),
            $this->admin->id
        );
    }

    private function breakdownFor(ScholarshipRefrend $refrend): array
    {
        return $this->breakdown->forRefrend($refrend->fresh());
    }

    private function f(mixed $v): float
    {
        return (float) ($v ?? 0);
    }

    /** Asserts the three reconciliation invariants of the breakdown. */
    private function assertReconciles(array $b): void
    {
        $this->assertEqualsWithDelta(
            $this->f($b['gross_amount']),
            $this->f($b['base_amount']) + $this->f($b['monto_apoyo']) + $this->f($b['temporary_increase_amount']),
            0.001,
            'base + apoyo + increase must equal gross'
        );

        $this->assertEqualsWithDelta(
            $this->f($b['final_amount']),
            $this->f($b['gross_amount'])
                - $this->f($b['academic_discount_amount'])
                - $this->f($b['discount_amount'])
                + $this->f($b['telmex_covered_amount'])
                + $this->f($b['manual_adjustment']),
            0.001,
            'gross - academic - discount + covered + manual must equal final'
        );

        $this->assertEqualsWithDelta(
            $this->f($b['total_to_pay']),
            $this->f($b['final_amount'])
                + $this->f($b['amount_pending_from_previous'])
                + $this->f($b['advance_payment_amount']),
            0.001,
            'final + pending + advance must equal total_to_pay'
        );
    }

    // ── backward compatibility ───────────────────────────────────────────

    /** @test */
    public function it_keeps_every_legacy_key(): void
    {
        $b = $this->breakdownFor($this->generate($this->makeProfile()));

        foreach ([
            'base_amount', 'temporary_increase_amount', 'temporary_increase_reason',
            'discount_percentage', 'discount_amount', 'amount_pending_from_previous',
            'final_amount', 'total_to_pay',
        ] as $key) {
            $this->assertArrayHasKey($key, $b);
        }
        $this->assertSame('1000.00', $b['final_amount']);
        $this->assertSame('1000.00', $b['total_to_pay']);
    }

    // ── IU ────────────────────────────────────────────────────────────────

    /** @test */
    public function iu_with_monto_apoyo_reconciles_the_reported_1020_case(): void
    {
        $b = $this->breakdownFor($this->generate($this->makeProfile(['monto_apoyo' => 20])));

        $this->assertSame('IU', $b['scholarship_type']);
        $this->assertSame('1000.00', $b['base_amount']);
        $this->assertSame('20.00', $b['monto_apoyo']);
        $this->assertSame('1020.00', $b['gross_amount']);
        $this->assertSame('1020.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function iu_with_temporary_increase_reconciles(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 20,
            'temporary_increase_valid_from'  => '2026-05-01',
            'temporary_increase_valid_until' => '2026-05-31',
            'temporary_increase_reason'      => 'Apoyo transporte',
        ]);
        $b = $this->breakdownFor($this->generate($profile));

        $this->assertSame('20.00', $b['temporary_increase_amount']);
        $this->assertSame('Apoyo transporte', $b['temporary_increase_reason']);
        $this->assertSame('1020.00', $b['gross_amount']);
        $this->assertSame('1020.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function iu_with_academic_discount_exposes_the_academic_line(): void
    {
        $profile = $this->makeProfile(array_merge(['monto_apoyo' => 100], $this->academicDiscount(10)));
        $b       = $this->breakdownFor($this->generate($profile));

        $this->assertSame('1100.00', $b['gross_amount']);
        $this->assertSame('10.00', $b['academic_discount_percentage']);
        $this->assertSame('Promedio bajo', $b['academic_discount_reason']);
        $this->assertSame('110.00', $b['academic_discount_amount']);
        $this->assertSame('990.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function it_returns_null_academic_keys_without_an_academic_discount(): void
    {
        $b = $this->breakdownFor($this->generate($this->makeProfile()));

        $this->assertNull($b['academic_discount_percentage']);
        $this->assertNull($b['academic_discount_reason']);
        $this->assertEquals(0, $this->f($b['academic_discount_amount']));
    }

    /** @test */
    public function iu_with_attendance_penalty_rows_reconciles(): void
    {
        $refrend = $this->generate($this->makeProfile(array_merge(['monto_apoyo' => 200], $this->academicDiscount(10))));
        foreach ([[DiscountType::RETARDOS, 10], [DiscountType::FALTA_INJUSTIFICADA, 20]] as [$type, $pct]) {
            ScholarshipRefrendDiscount::create([
                'scholarship_refrend_id' => $refrend->id,
                'discount_type'          => $type->value,
                'discount_percentage'    => $pct,
                'description'            => 'penalty',
            ]);
        }
        $this->app->make(ScholarshipCalculationService::class)->recalculate($refrend->fresh());

        $b = $this->breakdownFor($refrend);

        // gross 1200, academic 120 -> 1080, penalties 30% of 1080 = 324
        $this->assertSame('120.00', $b['academic_discount_amount']);
        $this->assertSame('324.00', $b['discount_amount']);
        $this->assertSame('756.00', $b['final_amount']);
        $this->assertSame('Descuentos por penalizaciones', $b['discount_label']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    // ── Telmex ────────────────────────────────────────────────────────────

    /** @test */
    public function telmex_iu_with_active_coverage_adds_the_covered_line(): void
    {
        $profile = $this->makeProfile([
            'scholarship_type'  => ScholarshipType::TELMEX_IU->value,
            'monthly_amount'    => 1500,
            'monto_apoyo'       => 100,
            'iu_payment_amount' => 600,
        ]);
        $this->withCoverage($profile, ScholarshipType::TELMEX_IU->value);

        $b = $this->breakdownFor($this->generate($profile));

        $this->assertSame('TELMEX_IU', $b['scholarship_type']);
        $this->assertSame('600.00', $b['base_amount']);
        $this->assertNull($b['monto_apoyo']);
        $this->assertSame('600.00', $b['gross_amount']);
        $this->assertSame('1600.00', $b['telmex_covered_amount']);
        $this->assertSame('2200.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function telmex_iu_without_coverage_has_no_covered_line(): void
    {
        $profile = $this->makeProfile([
            'scholarship_type'  => ScholarshipType::TELMEX_IU->value,
            'monthly_amount'    => 1500,
            'iu_payment_amount' => 600,
        ]);
        $b = $this->breakdownFor($this->generate($profile));

        $this->assertEquals(0, $this->f($b['telmex_covered_amount']));
        $this->assertSame('600.00', $b['final_amount']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function pure_telmex_pays_only_the_covered_amount(): void
    {
        $profile = $this->makeProfile([
            'scholarship_type' => ScholarshipType::TELMEX->value,
            'monthly_amount'   => 1800,
            'monto_apoyo'      => 150,
        ]);
        $this->withCoverage($profile, ScholarshipType::TELMEX->value);

        $b = $this->breakdownFor($this->generate($profile));

        $this->assertSame('TELMEX', $b['scholarship_type']);
        $this->assertNull($b['monto_apoyo']);
        $this->assertSame('0.00', $b['gross_amount']);
        $this->assertSame('1950.00', $b['telmex_covered_amount']);
        $this->assertSame('1950.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function telmex_iu_sin_pago_still_pays_the_covered_part_and_labels_the_discount(): void
    {
        $profile = $this->makeProfile([
            'scholarship_type'  => ScholarshipType::TELMEX_IU->value,
            'monthly_amount'    => 1500,
            'iu_payment_amount' => 600,
        ]);
        $this->withCoverage($profile, ScholarshipType::TELMEX_IU->value);
        $refrend = $this->resolve($this->generate($profile), 'SIN_PAGO');

        $b = $this->breakdownFor($refrend);

        $this->assertSame('600.00', $b['discount_amount']);
        $this->assertSame('Sin pago', $b['discount_label']);
        $this->assertSame('1500.00', $b['telmex_covered_amount']);
        $this->assertSame('1500.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    // ── resolutions ───────────────────────────────────────────────────────

    private function resolvedIu(string $type, array $extra = []): array
    {
        $profile = $this->makeProfile(array_merge(['monto_apoyo' => 0], $this->academicDiscount(10)));

        return $this->breakdownFor($this->resolve($this->generate($profile), $type, $extra));
    }

    /** @test */
    public function beca_mes_pays_the_full_due_amount(): void
    {
        $b = $this->resolvedIu('BECA_MES');

        $this->assertSame('900.00', $b['final_amount']);
        $this->assertEquals(0, $this->f($b['discount_amount']));
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function beca_mes_neutralizes_penalty_rows_and_still_reconciles(): void
    {
        $refrend = $this->generate($this->makeProfile());
        ScholarshipRefrendDiscount::create([
            'scholarship_refrend_id' => $refrend->id,
            'discount_type'          => DiscountType::RETARDOS->value,
            'discount_percentage'    => 25,
            'description'            => 'penalty',
        ]);
        $this->app->make(ScholarshipCalculationService::class)->recalculate($refrend->fresh());

        $b = $this->breakdownFor($this->resolve($refrend->fresh(), 'BECA_MES'));

        $this->assertSame('1000.00', $b['final_amount']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function sin_pago_zeroes_the_final_amount(): void
    {
        $b = $this->resolvedIu('SIN_PAGO');

        $this->assertSame('900.00', $b['discount_amount']);
        $this->assertSame('Sin pago', $b['discount_label']);
        $this->assertSame('0.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function retenida_with_a_fixed_amount_reconciles(): void
    {
        $b = $this->resolvedIu('RETENIDA', ['withholding_mode' => 'fixed', 'withholding_value' => 250]);

        $this->assertSame('250.00', $b['discount_amount']);
        $this->assertSame('Retención', $b['discount_label']);
        $this->assertSame('650.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function retenida_with_a_percentage_reconciles(): void
    {
        $b = $this->resolvedIu('RETENIDA', ['withholding_mode' => 'percentage', 'withholding_value' => 50]);

        $this->assertSame('450.00', $b['discount_amount']);
        $this->assertSame('450.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function descuento_definitivo_reconciles_and_is_labelled(): void
    {
        $b = $this->resolvedIu('DESCUENTO_DEFINITIVO', ['withholding_mode' => 'percentage', 'withholding_value' => 20]);

        $this->assertSame('180.00', $b['discount_amount']);
        $this->assertSame('Descuento definitivo', $b['discount_label']);
        $this->assertSame('720.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function baja_definitiva_reconciles_with_no_covered_part(): void
    {
        $b = $this->resolvedIu('BAJA_DEFINITIVA');

        $this->assertSame('900.00', $b['discount_amount']);
        $this->assertSame('Baja definitiva', $b['discount_label']);
        $this->assertSame('0.00', $b['final_amount']);
        $this->assertEquals(0, $this->f($b['telmex_covered_amount']));
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function egresado_reconciles_and_is_labelled_egreso(): void
    {
        $b = $this->resolvedIu('EGRESADO');

        $this->assertSame('Egreso', $b['discount_label']);
        $this->assertSame('0.00', $b['final_amount']);
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function a_later_refrend_closed_in_cascade_is_labelled_cierre_de_ciclo(): void
    {
        $profile = $this->makeProfile($this->academicDiscount(10));
        $cut     = $this->generate($profile, self::MONTH - 1);
        $later   = $this->generate($profile, self::MONTH);

        $this->resolve($cut, 'BAJA_DEFINITIVA');

        $bLater = $this->breakdownFor($later);
        $this->assertSame('Cierre de ciclo', $bLater['discount_label']);
        $this->assertSame('900.00', $bLater['discount_amount']);
        $this->assertSame('0.00', $bLater['final_amount']);
        $this->assertNull($bLater['manual_adjustment']);
        $this->assertReconciles($bLater);

        // The cut refrend itself keeps its own label.
        $this->assertSame('Baja definitiva', $this->breakdownFor($cut)['discount_label']);
    }

    /** @test */
    public function the_automatic_reticula_egreso_refrend_reconciles_instead_of_faking_a_manual_adjustment(): void
    {
        // Reticula ends 2026-03-31 -> automatic $0 egreso refrend in 2026-05.
        $profile = $this->makeProfile(array_merge(
            ['reticula_end_date' => '2026-03-31'],
            $this->academicDiscount(10)
        ));
        $refrend = $this->generate($profile);
        $this->assertSame(ScholarshipRefrend::RESOLUTION_EGRESO_RETICULA, $refrend->resolution_type);

        $b = $this->breakdownFor($refrend);

        $this->assertSame('0.00', $b['final_amount']);
        $this->assertSame('Egreso por retícula', $b['discount_label']);
        $this->assertSame('900.00', $b['discount_amount']);
        $this->assertEquals(0, $this->f($b['telmex_covered_amount']));
        $this->assertNull($b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    // ── manual override ──────────────────────────────────────────────────

    /** @test */
    public function a_manual_override_surfaces_as_a_manual_adjustment(): void
    {
        $refrend = $this->generate($this->makeProfile(['monto_apoyo' => 20]));

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/scholarship-refrends/{$refrend->id}/inline", ['final_amount_override' => 800])
            ->assertOk();

        $b = $this->breakdownFor($refrend);

        $this->assertSame('800.00', $b['final_amount']);
        $this->assertSame('-220.00', $b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function a_positive_manual_override_over_a_covered_refrend_reconciles(): void
    {
        $profile = $this->makeProfile([
            'scholarship_type'  => ScholarshipType::TELMEX_IU->value,
            'monthly_amount'    => 1500,
            'iu_payment_amount' => 600,
        ]);
        $this->withCoverage($profile, ScholarshipType::TELMEX_IU->value);
        $refrend = $this->generate($profile);

        $this->actingAs($this->admin)
            ->patchJson("/api/admin/scholarship-refrends/{$refrend->id}/inline", ['final_amount_override' => 3000])
            ->assertOk();

        $b = $this->breakdownFor($refrend);

        $this->assertSame('1500.00', $b['telmex_covered_amount']);
        $this->assertSame('900.00', $b['manual_adjustment']);
        $this->assertReconciles($b);
    }

    // ── pending + advance ────────────────────────────────────────────────

    /** @test */
    public function pending_from_previous_and_advance_payment_reconcile_the_total(): void
    {
        $refrend = $this->generate($this->makeProfile(['monto_apoyo' => 20]));
        $refrend->update(['amount_pending_from_previous' => 450, 'advance_payment_amount' => 300]);

        $b = $this->breakdownFor($refrend);

        $this->assertSame('450.00', $b['amount_pending_from_previous']);
        $this->assertSame('300.00', $b['advance_payment_amount']);
        $this->assertSame('1770.00', $b['total_to_pay']);
        $this->assertReconciles($b);
    }

    /** @test */
    public function advance_payment_amount_is_null_or_zero_when_absent(): void
    {
        $b = $this->breakdownFor($this->generate($this->makeProfile()));

        $this->assertEquals(0, $this->f($b['advance_payment_amount']));
    }
}
