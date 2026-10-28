<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Generation;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use App\Services\Scholarship\PaymentBatchService;
use App\Services\Scholarship\RefrendAmountBreakdown;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/admin/scholarship-payments/by-generation: per-user `rows`,
 * NO_IU_PAYMENT rows (Telmex excluded by Filter A) and the reconciled
 * summary figures (temporary_increase_amount, pending_and_advance_amount,
 * difference_amount = true discounts and retentions).
 */
class GenerationPaymentRowsTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    /** Money columns of a row that add up to total_to_pay. */
    private const MONEY_COLUMNS = [
        'beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage',
        'discounts', 'pending_and_advance', 'total_to_pay',
    ];

    private User $admin;
    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');

        $this->generation = Generation::create([
            'generation_name'   => 'Gen Test',
            'campus'            => self::CAMPUS,
            'generation_active' => true,
        ]);
    }

    private function makeRefrend(array $overrides = [], array $userOverrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create(array_merge([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => self::CAMPUS,
            'active'    => true,
        ], $userOverrides));

        ScholarshipPaymentData::create([
            'user_id'        => $user->id,
            'bank_name'      => 'BBVA',
            'account_number' => '0123456789',
            'curp'           => 'CURP010101HDFXXX01',
            'rfc'            => 'PEPJ800101ABC',
        ]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'base_amount'                  => 1000.00,
            'snapshot_gross_amount'        => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation_id'       => $this->generation->id,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    /** IU with apoyo 100, increase 300, 10% academic discount (240) and a 100 penalty. */
    private function makeIuWithEverything(string $name = 'Alvarez Ruiz'): ScholarshipRefrend
    {
        return $this->makeRefrend([
            'snapshot_name'                      => $name,
            'base_amount'                        => 2000.00,
            'snapshot_monto_apoyo'               => 100.00,
            'snapshot_temporary_increase_amount' => 300.00,
            'snapshot_temporary_increase_reason' => 'Beca extra',
            'snapshot_gross_amount'              => 2400.00,
            'snapshot_discount_percentage'       => 10.00,
            'snapshot_discount_reason'           => 'Promedio',
            'discount_amount'                    => 100.00,
            'final_amount'                       => 2060.00,
        ]);
    }

    /** TELMEX_IU: IU pays base 800, Telmex coverage of 3500 is included in final. */
    private function makeTelmexIuWithCoverage(string $name = 'Basto Cruz'): ScholarshipRefrend
    {
        $refrend  = $this->makeRefrend([
            'snapshot_name'                  => $name,
            'snapshot_scholarship_type'      => ScholarshipType::TELMEX_IU->value,
            'base_amount'                    => 800.00,
            'snapshot_gross_amount'          => 800.00,
            'snapshot_telmex_covered_amount' => 3500.00,
            'final_amount'                   => 4300.00,
        ]);
        $coverage = TelmexCoverage::create([
            'user_id'                        => $refrend->user_id,
            'scholarship_type_at_activation' => ScholarshipType::TELMEX_IU->value,
            'start_period'                   => '2026-01-01',
            'end_period'                     => null,
            'status'                         => 'ACTIVA',
        ]);
        $refrend->update(['snapshot_telmex_coverage_id' => $coverage->id]);

        return $refrend;
    }

    private function makeTelmexWithIncrease(string $name = 'Castro Diaz'): ScholarshipRefrend
    {
        return $this->makeRefrend([
            'snapshot_name'                      => $name,
            'snapshot_scholarship_type'          => ScholarshipType::TELMEX->value,
            'base_amount'                        => 0.00,
            'snapshot_temporary_increase_amount' => 500.00,
            'snapshot_gross_amount'              => 500.00,
            'final_amount'                       => 500.00,
        ]);
    }

    private function makePureTelmex(string $name = 'Davila Paz', array $overrides = []): ScholarshipRefrend
    {
        return $this->makeRefrend(array_merge([
            'snapshot_name'             => $name,
            'snapshot_scholarship_type' => ScholarshipType::TELMEX->value,
            'base_amount'               => 0.00,
            'snapshot_gross_amount'     => 0.00,
            'final_amount'              => 0.00,
        ], $overrides));
    }

    private function makePaid(string $name = 'Escobar Paz'): ScholarshipRefrend
    {
        $batch = ScholarshipPaymentBatch::create([
            'generation_id'   => $this->generation->id,
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => self::MONTH,
            'refrend_count'   => 1,
            'total_amount'    => '1000.00',
            'processed_by_id' => $this->admin->id,
            'processed_at'    => now(),
        ]);

        return $this->makeRefrend([
            'snapshot_name'    => $name,
            'status'           => RefrendStatus::PAID->value,
            'workflow_status'  => 'PAID',
            'payment_batch_id' => $batch->id,
        ]);
    }

    private function fetch(?Generation $generation = null): array
    {
        $generation ??= $this->generation;

        $response = $this->actingAs($this->admin)->getJson(
            '/api/admin/scholarship-payments/by-generation?' . http_build_query([
                'generation_id' => $generation->id,
                'period_year'   => self::YEAR,
                'period_month'  => self::MONTH,
            ])
        );
        $response->assertOk();

        return $response->json('data');
    }

    private function rowFor(array $data, int $refrendId): array
    {
        $matches = array_values(array_filter($data['rows'], fn ($r) => $r['refrend_id'] === $refrendId));
        $this->assertCount(1, $matches, "Expected exactly one row for refrend {$refrendId}.");

        return $matches[0];
    }

    /** Reconciliation identity of a row, in cents to avoid float noise. */
    private function reconciles(array $row): bool
    {
        $c = fn (string $k) => (int) round((float) $row[$k] * 100);

        return $c('beca') + $c('apoyo') + $c('pago_iu') + $c('temporary_increase')
            + $c('telmex_coverage') - $c('discounts') + $c('pending_and_advance') === $c('total_to_pay');
    }

    // ── Row shape and per-type values ───────────────────────────────────────

    /** @test */
    public function an_iu_row_exposes_beca_apoyo_increase_and_folded_discounts(): void
    {
        $refrend = $this->makeIuWithEverything();

        $row = $this->rowFor($this->fetch(), $refrend->id);

        $this->assertSame($refrend->user_id, $row['user_id']);
        $this->assertSame($refrend->user->enrollment, $row['enrollment']);
        $this->assertSame('Alvarez Ruiz', $row['full_name']);
        $this->assertSame('IU', $row['scholarship_type']);
        $this->assertSame('2000.00', $row['beca']);
        $this->assertSame('100.00', $row['apoyo']);
        $this->assertSame('0.00', $row['pago_iu']);
        $this->assertSame('300.00', $row['temporary_increase']);
        $this->assertSame('0.00', $row['telmex_coverage']);
        // 240 academic (10% of 2400) + 100 penalty.
        $this->assertSame('340.00', $row['discounts']);
        $this->assertSame('0.00', $row['pending_and_advance']);
        $this->assertSame('2060.00', $row['total_to_pay']);
        $this->assertSame('PENDING', $row['state']);
        $this->assertTrue($this->reconciles($row));
    }

    /** @test */
    public function a_telmex_iu_row_puts_the_iu_payment_in_pago_iu_and_the_coverage_apart(): void
    {
        $refrend = $this->makeTelmexIuWithCoverage();

        $row = $this->rowFor($this->fetch(), $refrend->id);

        $this->assertSame('TELMEX_IU', $row['scholarship_type']);
        $this->assertSame('0.00', $row['beca']);
        $this->assertSame('0.00', $row['apoyo']);
        $this->assertSame('800.00', $row['pago_iu']);
        $this->assertSame('3500.00', $row['telmex_coverage']);
        $this->assertSame('0.00', $row['discounts']);
        $this->assertSame('4300.00', $row['total_to_pay']);
        $this->assertTrue($this->reconciles($row));
    }

    /** @test */
    public function a_telmex_row_with_an_increase_is_listed_with_only_the_increase(): void
    {
        $refrend = $this->makeTelmexWithIncrease();

        $row = $this->rowFor($this->fetch(), $refrend->id);

        $this->assertSame('TELMEX', $row['scholarship_type']);
        $this->assertSame('0.00', $row['beca']);
        $this->assertSame('0.00', $row['pago_iu']);
        $this->assertSame('500.00', $row['temporary_increase']);
        $this->assertSame('500.00', $row['total_to_pay']);
        $this->assertSame('PENDING', $row['state']);
        $this->assertTrue($this->reconciles($row));
    }

    /** @test */
    public function pending_from_previous_and_advance_are_folded_into_pending_and_advance(): void
    {
        $refrend = $this->makeRefrend([
            'amount_pending_from_previous' => 200.00,
            'advance_payment_amount'       => 50.00,
        ]);

        $row = $this->rowFor($this->fetch(), $refrend->id);

        $this->assertSame('250.00', $row['pending_and_advance']);
        $this->assertSame('1250.00', $row['total_to_pay']);
        $this->assertSame('0.00', $row['discounts']);
        $this->assertTrue($this->reconciles($row));
    }

    /** @test */
    public function a_manual_final_amount_override_is_folded_into_discounts_with_the_right_sign(): void
    {
        $lowered = $this->makeRefrend(['final_amount' => 900.00]);   // staff lowered by 100
        $raised  = $this->makeRefrend(['final_amount' => 1100.00]);  // staff raised by 100

        $data = $this->fetch();

        $this->assertSame('100.00', $this->rowFor($data, $lowered->id)['discounts']);
        $this->assertSame('-100.00', $this->rowFor($data, $raised->id)['discounts']);
        $this->assertTrue($this->reconciles($this->rowFor($data, $lowered->id)));
        $this->assertTrue($this->reconciles($this->rowFor($data, $raised->id)));
    }

    // ── States ──────────────────────────────────────────────────────────────

    /** @test */
    public function paid_pending_and_blocked_rows_get_their_state(): void
    {
        $paid    = $this->makePaid();
        $pending = $this->makeRefrend();
        $blocked = $this->makeRefrend(['workflow_status' => 'CON_INCIDENCIA']);

        $data = $this->fetch();

        $this->assertSame('PAID', $this->rowFor($data, $paid->id)['state']);
        $this->assertSame('PENDING', $this->rowFor($data, $pending->id)['state']);
        $this->assertSame('BLOCKED', $this->rowFor($data, $blocked->id)['state']);
    }

    // ── NO_IU_PAYMENT (Telmex rows excluded by Filter A) ────────────────────

    /** @test */
    public function pure_telmex_without_increase_or_coverage_is_listed_as_no_iu_payment_with_zeros(): void
    {
        $telmex = $this->makePureTelmex();

        $data = $this->fetch();
        $row  = $this->rowFor($data, $telmex->id);

        $this->assertSame('NO_IU_PAYMENT', $row['state']);
        $this->assertSame('TELMEX', $row['scholarship_type']);
        $this->assertSame('Davila Paz', $row['full_name']);
        $this->assertSame($telmex->user->enrollment, $row['enrollment']);
        foreach (self::MONEY_COLUMNS as $column) {
            $this->assertSame('0.00', $row[$column], "{$column} must be 0.00 for NO_IU_PAYMENT");
        }
        $this->assertSame(1, $data['summary']['telmex_without_iu_payment']);
    }

    /** @test */
    public function no_iu_payment_rows_never_affect_any_summary_figure(): void
    {
        $this->makeIuWithEverything();
        $before = $this->fetch()['summary'];

        $this->makePureTelmex();
        $this->makePureTelmex('Davila Otro');
        $after = $this->fetch()['summary'];

        $expected = $before;
        $expected['telmex_without_iu_payment'] = 2;
        $this->assertSame($expected, $after);
        $this->assertSame(1, $after['total'], 'total counts payment rows only');
        $this->assertSame(0, $before['telmex_without_iu_payment']);
    }

    /** @test */
    public function no_iu_payment_rows_honor_filter_c_campus_period_and_generation(): void
    {
        $this->makePureTelmex('Excluida Egresado', ['resolution_type' => 'EGRESADO']);
        $this->makePureTelmex('Otro Campus', ['snapshot_campus' => 'TIZIMIN']);
        $this->makePureTelmex('Otro Periodo', ['period_month' => self::MONTH + 1]);
        $otherGeneration = Generation::create([
            'generation_name' => 'Otra', 'campus' => self::CAMPUS, 'generation_active' => true,
        ]);
        $this->makePureTelmex('Otra Generacion', ['snapshot_generation_id' => $otherGeneration->id]);
        $included = $this->makePureTelmex('Incluida');

        $data = $this->fetch();

        $this->assertSame([$included->id], array_column($data['rows'], 'refrend_id'));
        $this->assertSame(1, $data['summary']['telmex_without_iu_payment']);
    }

    /** @test */
    public function a_telmex_row_with_coverage_but_no_increase_is_a_payment_row_not_no_iu_payment(): void
    {
        $refrend = $this->makePureTelmex('Cubierta Paz', [
            'snapshot_telmex_covered_amount' => 3500.00,
            'final_amount'                   => 3500.00,
        ]);
        $coverage = TelmexCoverage::create([
            'user_id'                        => $refrend->user_id,
            'scholarship_type_at_activation' => ScholarshipType::TELMEX->value,
            'start_period'                   => '2026-01-01',
            'end_period'                     => null,
            'status'                         => 'ACTIVA',
        ]);
        $refrend->update(['snapshot_telmex_coverage_id' => $coverage->id]);

        $data = $this->fetch();
        $row  = $this->rowFor($data, $refrend->id);

        $this->assertSame('PENDING', $row['state']);
        $this->assertSame('3500.00', $row['telmex_coverage']);
        $this->assertTrue($this->reconciles($row));
        $this->assertSame(0, $data['summary']['telmex_without_iu_payment']);
    }

    // ── Ordering ────────────────────────────────────────────────────────────

    /** @test */
    public function rows_are_sorted_by_full_name_in_a_spanish_friendly_order(): void
    {
        $this->makeRefrend(['snapshot_name' => 'Zavala']);
        $this->makeRefrend(['snapshot_name' => 'Álvarez']);
        $this->makeRefrend(['snapshot_name' => 'Bravo']);
        $this->makePureTelmex('Castro');

        $data = $this->fetch();

        $this->assertSame(['Álvarez', 'Bravo', 'Castro', 'Zavala'], array_column($data['rows'], 'full_name'));
    }

    // ── Summary figures ─────────────────────────────────────────────────────

    /** @test */
    public function summary_exposes_temporary_increase_and_pending_and_advance_over_paid_and_pending_rows(): void
    {
        $this->makeIuWithEverything();                                  // increase 300
        $this->makeTelmexWithIncrease();                                // increase 500
        $this->makeRefrend([
            'amount_pending_from_previous' => 200.00,
            'advance_payment_amount'       => 50.00,
        ]);                                                             // pending+advance 250
        $this->makeRefrend([                                            // blocked: excluded
            'workflow_status'                    => 'CON_INCIDENCIA',
            'snapshot_temporary_increase_amount' => 999.00,
            'amount_pending_from_previous'       => 999.00,
        ]);

        $summary = $this->fetch()['summary'];

        $this->assertSame('800.00', $summary['temporary_increase_amount']);
        $this->assertSame('250.00', $summary['pending_and_advance_amount']);
    }

    /** @test */
    public function difference_amount_is_the_true_discounts_and_retentions(): void
    {
        $this->makeIuWithEverything();       // discounts 340
        $this->makeTelmexIuWithCoverage();   // 0
        $this->makeTelmexWithIncrease();     // 0
        $this->makeRefrend([                 // pending + advance, no discounts
            'amount_pending_from_previous' => 200.00,
            'advance_payment_amount'       => 50.00,
        ]);

        $summary = $this->fetch()['summary'];

        // Old formula (without increase / pending+advance) would give a
        // negative number here; the new one is exactly the discounts.
        $this->assertSame('340.00', $summary['difference_amount']);
    }

    // ── Rows <-> summary consistency ────────────────────────────────────────

    /** @test */
    public function every_row_reconciles_and_paid_plus_pending_rows_sum_to_the_summary(): void
    {
        $this->makeIuWithEverything();
        $this->makeTelmexIuWithCoverage();
        $this->makeTelmexWithIncrease();
        $this->makePureTelmex();
        $this->makePaid();
        $this->makeRefrend(['amount_pending_from_previous' => 200.00, 'advance_payment_amount' => 50.00]);
        $this->makeRefrend(['final_amount' => 900.00]);
        $this->makeRefrend([
            'workflow_status'                    => 'CON_INCIDENCIA',
            'snapshot_temporary_increase_amount' => 100.00,
            'snapshot_gross_amount'              => 1100.00,
            'final_amount'                       => 1100.00,
        ]);

        $data    = $this->fetch();
        $summary = $data['summary'];

        foreach ($data['rows'] as $row) {
            $this->assertTrue($this->reconciles($row), "Row {$row['refrend_id']} must reconcile.");
        }

        $counted = array_filter($data['rows'], fn ($r) => in_array($r['state'], ['PAID', 'PENDING'], true));
        $sum     = fn (string $column) => number_format(array_sum(array_map(fn ($r) => (float) $r[$column], $counted)), 2, '.', '');

        $this->assertSame($summary['beca_amount'], $sum('beca'));
        $this->assertSame($summary['apoyo_amount'], $sum('apoyo'));
        $this->assertSame($summary['pago_iu_amount'], $sum('pago_iu'));
        $this->assertSame($summary['temporary_increase_amount'], $sum('temporary_increase'));
        $this->assertSame($summary['telmex_coverage_amount'], $sum('telmex_coverage'));
        $this->assertSame($summary['pending_and_advance_amount'], $sum('pending_and_advance'));
        $this->assertSame($summary['total_amount'], $sum('total_to_pay'));
        $this->assertSame($summary['difference_amount'], $sum('discounts'));

        $this->assertSame(
            $summary['total'],
            count(array_filter($data['rows'], fn ($r) => $r['state'] !== 'NO_IU_PAYMENT'))
        );
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(1, $summary['telmex_without_iu_payment']);
    }

    /** Breakdown-based discounts: academic + penalty/retention - manual adjustment. */
    private function breakdownDiscounts(ScholarshipRefrend $refrend): float
    {
        $b = app(RefrendAmountBreakdown::class)->forRefrend($refrend->fresh());

        return (float) $b['academic_discount_amount'] + (float) $b['discount_amount'] - (float) ($b['manual_adjustment'] ?? 0);
    }

    /** @test */
    public function row_discounts_match_the_refrend_amount_breakdown_for_every_type(): void
    {
        $telmexCoveragePending = $this->makePureTelmex('Cubierta Pendiente', [
            'snapshot_telmex_covered_amount' => 3500.00,
            'final_amount'                   => 3500.00,
            'amount_pending_from_previous'   => 200.00,
        ]);
        $coverage = TelmexCoverage::create([
            'user_id'                        => $telmexCoveragePending->user_id,
            'scholarship_type_at_activation' => ScholarshipType::TELMEX->value,
            'start_period'                   => '2026-01-01',
            'end_period'                     => null,
            'status'                         => 'ACTIVA',
        ]);
        $telmexCoveragePending->update(['snapshot_telmex_coverage_id' => $coverage->id]);

        $refrends = [
            $this->makeIuWithEverything(),
            $this->makeTelmexIuWithCoverage(),
            $this->makeTelmexWithIncrease(),
            $this->makeRefrend(['final_amount' => 900.00]),    // manual override down
            $this->makeRefrend(['final_amount' => 1100.00]),   // manual override up
            $this->makeRefrend(['amount_pending_from_previous' => 200.00, 'advance_payment_amount' => 50.00]),
            $telmexCoveragePending,
        ];

        $data = $this->fetch();

        foreach ($refrends as $refrend) {
            $b   = app(RefrendAmountBreakdown::class)->forRefrend($refrend->fresh());
            $row = $this->rowFor($data, $refrend->id);

            $this->assertEqualsWithDelta($this->breakdownDiscounts($refrend), (float) $row['discounts'], 0.001, "discounts of refrend {$refrend->id}");
            $this->assertEqualsWithDelta((float) $b['telmex_covered_amount'], (float) $row['telmex_coverage'], 0.001);
            $this->assertEqualsWithDelta((float) $b['total_to_pay'], (float) $row['total_to_pay'], 0.001);
            $this->assertEqualsWithDelta(
                (float) $b['amount_pending_from_previous'] + (float) $b['advance_payment_amount'],
                (float) $row['pending_and_advance'],
                0.001
            );
        }
    }

    /**
     * KNOWN DIVERGENCE (characterization): `discounts` is the residual of the
     * row's columns against total_to_pay, while the breakdown derives it from
     * the stored discount fields. They agree whenever the frozen snapshot is
     * internally consistent (base + apoyo + increase = gross, which every
     * generation path guarantees). If a snapshot is inconsistent, the residual
     * absorbs the gap, so the row still reconciles but `discounts` is larger
     * than the breakdown's by exactly (base + apoyo + increase - gross).
     *
     * @test
     */
    public function an_inconsistent_snapshot_makes_discounts_diverge_from_the_breakdown_by_exactly_the_gap(): void
    {
        // base 1000 + apoyo 100 should give gross 1100, but gross says 1000,
        // with no discount of any kind and final 1000.
        $refrend = $this->makeRefrend([
            'base_amount'           => 1000.00,
            'snapshot_monto_apoyo'  => 100.00,
            'snapshot_gross_amount' => 1000.00,
            'final_amount'          => 1000.00,
        ]);

        $row = $this->rowFor($this->fetch(), $refrend->id);

        $this->assertEqualsWithDelta(0.0, $this->breakdownDiscounts($refrend), 0.001);
        $this->assertSame('100.00', $row['discounts']);
        $this->assertTrue($this->reconciles($row), 'the residual keeps the row reconciled');
    }

    /** @test */
    public function the_payable_floor_shifts_discounts_one_cent_below_the_breakdown(): void
    {
        // Fully discounted IU row: total_to_pay 0.00 floors to 0.01 for the bank file.
        $refrend = $this->makeRefrend(['discount_amount' => 1000.00, 'final_amount' => 0.00]);

        $row = $this->rowFor($this->fetch(), $refrend->id);

        $this->assertSame('0.01', $row['total_to_pay']);
        $this->assertSame('999.99', $row['discounts']);
        $this->assertEqualsWithDelta(1000.00, $this->breakdownDiscounts($refrend), 0.001);
        $this->assertEqualsWithDelta(
            0.01,
            $this->breakdownDiscounts($refrend) - (float) $row['discounts'],
            0.0001,
            'the floor is the only source of delta against the breakdown'
        );
        $this->assertTrue($this->reconciles($row));
    }

    // ── Pure TELMEX owing money from other months (sdd/telmex-pendiente-lote) ──

    /** @test */
    public function a_pure_telmex_row_with_pending_and_advance_is_a_payment_row_not_no_iu_payment(): void
    {
        $telmex = $this->makePureTelmex('Pendiente Paz', [
            'amount_pending_from_previous' => 200.00,
            'advance_payment_amount'       => 50.00,
        ]);
        $data = $this->fetch();
        $row  = $this->rowFor($data, $telmex->id);

        $this->assertSame('PENDING', $row['state']);
        $this->assertSame('250.00', $row['pending_and_advance']);
        $this->assertSame('250.00', $row['total_to_pay']);
        foreach (['beca', 'apoyo', 'pago_iu', 'temporary_increase', 'telmex_coverage', 'discounts'] as $column) {
            $this->assertSame('0.00', $row[$column], "{$column} must be 0.00");
        }
        $this->assertTrue($this->reconciles($row));

        $this->assertSame(0, $data['summary']['telmex_without_iu_payment']);
        $this->assertSame('250.00', $data['summary']['pending_and_advance_amount']);
    }

    /** @test */
    public function a_pure_telmex_row_owing_money_without_bank_data_is_blocked_not_no_iu_payment(): void
    {
        $telmex = $this->makePureTelmex('Sin Datos', ['amount_pending_from_previous' => 100.00]);
        ScholarshipPaymentData::where('user_id', $telmex->user_id)->delete();

        $row = $this->rowFor($this->fetch(), $telmex->id);

        $this->assertSame('BLOCKED', $row['state']);
        $this->assertSame('100.00', $row['total_to_pay']);
    }

    /** @test */
    public function a_pure_telmex_row_owing_nothing_is_no_iu_payment_and_not_in_the_batch_rows(): void
    {
        $telmex = $this->makePureTelmex('Nada Debe', [
            'amount_pending_from_previous' => 0,
            'advance_payment_amount'       => 0,
        ]);
        $data = $this->fetch();

        $this->assertSame('NO_IU_PAYMENT', $this->rowFor($data, $telmex->id)['state']);
        $this->assertSame(1, $data['summary']['telmex_without_iu_payment']);
        $this->assertSame(
            [],
            app(PaymentBatchService::class)->rows(self::CAMPUS, self::YEAR, self::MONTH, $this->generation->id)
        );
    }

    /** @test */
    public function every_telmex_fixture_is_in_exactly_one_of_batch_rows_or_no_iu_payment(): void
    {
        $this->makePureTelmex('Uno', ['amount_pending_from_previous' => 100.00]);
        $this->makePureTelmex('Dos', ['advance_payment_amount' => 30.50]);
        $this->makePureTelmex('Tres');
        $this->makeTelmexWithIncrease('Cuatro');
        $this->makeTelmexIuWithCoverage('Cinco');
        $this->makeRefrend(['snapshot_name' => 'Seis']);

        $data      = $this->fetch();
        $batchRows = app(PaymentBatchService::class)->rows(self::CAMPUS, self::YEAR, self::MONTH, $this->generation->id);
        $batchIds  = array_column($batchRows, 'refrend_id');
        $noIuIds   = array_column(
            array_filter($data['rows'], fn ($r) => $r['state'] === 'NO_IU_PAYMENT'),
            'refrend_id'
        );

        $this->assertSame([], array_intersect($batchIds, $noIuIds));
        $this->assertCount(6, array_unique(array_merge($batchIds, $noIuIds)));
        $this->assertCount(1, $noIuIds);
    }

    /** @test */
    public function the_summary_no_longer_exposes_the_no_iu_pending_amount(): void
    {
        $this->makePureTelmex('Uno', ['amount_pending_from_previous' => 100.00]);
        $this->makePureTelmex('Tres');

        $summary = $this->fetch()['summary'];

        $this->assertArrayNotHasKey('telmex_without_iu_payment_pending_amount', $summary);
        $this->assertArrayHasKey('telmex_without_iu_payment', $summary);
    }

    /** @test */
    public function an_empty_period_returns_empty_rows_and_zero_new_summary_figures(): void
    {
        $data = $this->fetch();

        $this->assertSame([], $data['rows']);
        $this->assertSame('0.00', $data['summary']['temporary_increase_amount']);
        $this->assertSame('0.00', $data['summary']['pending_and_advance_amount']);
        $this->assertSame(0, $data['summary']['telmex_without_iu_payment']);
        $this->assertArrayNotHasKey('telmex_without_iu_payment_pending_amount', $data['summary']);
    }
}
