<?php

namespace Tests\Feature;

use App\Actions\Scholarship\RevertAdvancePaymentAction;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\TelmexCoverage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S3 (spec R1-R5): POST
 * scholarship-refrends/{refrend}/advance-payment/revert.
 */
class RevertAdvancePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Se capturó el adelanto por error';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        Carbon::setTestNow(Carbon::create(2026, 9, 15));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $originOverrides
     * @return array{user: User, origin: ScholarshipRefrend, header: ScholarshipAdvancePayment, futures: array<int, ScholarshipRefrend>, months: array<int, ScholarshipAdvancePaymentMonth>}
     */
    private function makeAdvance(array $originOverrides = [], int $monthsCount = 3): array
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        ScholarshipProfile::create([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
            'payment_start_date'  => '2026-01-01',
            'config_set_at'       => now(),
        ]);

        $origin = $this->makeRefrend($user->id, 2026, 9, array_merge([
            'advance_payment_amount' => 1000.00 * $monthsCount,
        ], $originOverrides));

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 9,
            'months_count'        => $monthsCount,
            'total_amount'        => 1000.00 * $monthsCount,
            'status'              => 'ACTIVE',
            'cause'               => 'Viaje académico',
            'notes'               => 'Nota de captura',
            'created_by_id'       => $this->admin->id,
        ]);

        $futures = [];
        $months  = [];
        for ($i = 1; $i <= $monthsCount; $i++) {
            $month     = 9 + $i;
            $future    = $this->makeRefrend($user->id, 2026, $month, ['created_via' => 'ADVANCE_PAYMENT']);
            $futures[] = $future;
            $months[]  = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id,
                'user_id'            => $user->id,
                'period_year'        => 2026,
                'period_month'       => $month,
                'amount'             => 1000.00,
                'refrend_id'         => $future->id,
                'status'             => 'PENDING',
            ]);
        }

        return compact('user', 'origin', 'header', 'futures', 'months');
    }

    /** @param array<string, mixed> $overrides */
    private function makeRefrend(int $userId, int $year, int $month, array $overrides = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => $year,
            'period_month'                 => $month,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function url(int $refrendId): string
    {
        return "/api/admin/scholarship-refrends/{$refrendId}/advance-payment/revert";
    }

    private function revert(int $refrendId, ?string $reason = self::REASON)
    {
        return $this->actingAs($this->admin)->postJson(
            $this->url($refrendId),
            $reason === null ? [] : ['reason' => $reason]
        );
    }

    /** @param array<string, mixed> $f */
    private function assertAdvanceIntact(array $f): void
    {
        $this->assertSame(1, ScholarshipAdvancePayment::where('id', $f['header']->id)->count());
        $this->assertSame(count($f['months']), ScholarshipAdvancePaymentMonth::where('advance_payment_id', $f['header']->id)->count());
        $this->assertSame(
            count($f['futures']),
            ScholarshipRefrend::whereIn('id', array_map(fn ($r) => $r->id, $f['futures']))->count()
        );
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_REVERTED')->count());
        $this->assertEquals(
            1000.00 * count($f['months']),
            (float) $f['origin']->fresh()->advance_payment_amount
        );
    }

    // ---------------------------------------------------------------- R1

    /** @test */
    public function it_reverts_an_advance_deleting_header_months_and_futures(): void
    {
        $f         = $this->makeAdvance();
        $futureIds = array_map(fn ($r) => $r->id, $f['futures']);

        $response = $this->revert($f['origin']->id);

        $response->assertStatus(200);
        $response->assertJson(['res' => true]);
        $this->assertSame($f['origin']->id, $response->json('data.id'));

        $this->assertSame(0, ScholarshipAdvancePayment::count());
        $this->assertSame(0, ScholarshipAdvancePaymentMonth::count());
        $this->assertSame(0, ScholarshipRefrend::whereIn('id', $futureIds)->count());
        $this->assertEquals(0, (float) $f['origin']->fresh()->advance_payment_amount);
    }

    /** @test */
    public function it_writes_the_origin_log_with_reason_snapshot_and_actor(): void
    {
        $f         = $this->makeAdvance();
        $futureIds = array_map(fn ($r) => $r->id, $f['futures']);

        $this->revert($f['origin']->id)->assertStatus(200);

        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $f['origin']->id)
            ->where('action', 'ADVANCE_PAYMENT_REVERTED')
            ->sole();

        $this->assertSame(self::REASON, $log->notes);
        $this->assertSame($this->admin->id, $log->performed_by_id);

        $old = $log->old_values;
        $this->assertEquals(3000.00, (float) $old['advance_payment_amount']);
        $this->assertSame('DRAFT', $old['workflow_status']);
        $this->assertSame($futureIds, $old['deleted_refrend_ids']);
        $this->assertSame($f['header']->id, $old['advance_payment']['id']);
        $this->assertSame(3, $old['advance_payment']['months_count']);
        $this->assertSame('3000.00', $old['advance_payment']['total_amount']);
        $this->assertSame('Viaje académico', $old['advance_payment']['cause']);
        $this->assertSame('Nota de captura', $old['advance_payment']['notes']);
        $this->assertSame($this->admin->id, $old['advance_payment']['created_by_id']);
        $this->assertSame('ACTIVE', $old['advance_payment']['status']);
        $this->assertCount(3, $old['advance_payment']['months']);
        $this->assertSame(10, $old['advance_payment']['months'][0]['period_month']);
        $this->assertSame('1000.00', $old['advance_payment']['months'][0]['amount']);
        $this->assertSame($futureIds[0], $old['advance_payment']['months'][0]['refrend_id']);
        $this->assertSame('PENDING', $old['advance_payment']['months'][0]['status']);

        $new = $log->new_values;
        $this->assertEquals(0, (float) $new['advance_payment_amount']);
        $this->assertNull($new['advance_payment']);
        $this->assertSame('DRAFT', $new['workflow_status']);
    }

    /**
     * @test
     * @dataProvider revertableOriginStates
     */
    public function it_reverts_regardless_of_the_origin_workflow_state(string $workflow): void
    {
        $f = $this->makeAdvance(['workflow_status' => $workflow]);

        $this->revert($f['origin']->id)->assertStatus(200);

        $this->assertSame(0, ScholarshipAdvancePayment::count());
        $this->assertSame($workflow, $f['origin']->fresh()->workflow_status);
    }

    public function revertableOriginStates(): array
    {
        return [
            'draft'       => ['DRAFT'],
            'incident'    => ['CON_INCIDENCIA'],
            'ready'       => ['LISTO_PARA_PAGO'],
        ];
    }

    /** @test */
    public function it_leaves_every_other_origin_field_untouched(): void
    {
        $f = $this->makeAdvance([
            'workflow_status'  => 'CON_INCIDENCIA',
            'resolution_type'  => 'SIN_ESPECIFICAR',
            'resolution_cause' => 'Causa',
            'resolution_notes' => 'staff notes',
            'final_amount'     => 850.00,
            'discount_amount'  => 150.00,
        ]);
        $incident = $f['origin']->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'OTRO',
            'description'       => 'manual on origin',
            'priority'          => 'LOW',
            'created_by_id'     => $f['user']->id,
        ]);
        $discount = $f['origin']->discounts()->create([
            'discount_type'       => 'RETARDOS',
            'discount_percentage' => 10,
            'description'         => 'auto',
        ]);

        $before = $f['origin']->fresh()->getAttributes();

        $this->revert($f['origin']->id)->assertStatus(200);

        $after = $f['origin']->fresh()->getAttributes();
        unset($before['advance_payment_amount'], $before['updated_at'], $after['advance_payment_amount'], $after['updated_at']);
        $this->assertEquals($before, $after);

        $fresh = $f['origin']->fresh();
        $this->assertSame('CON_INCIDENCIA', $fresh->workflow_status);
        $this->assertSame('SIN_ESPECIFICAR', $fresh->resolution_type);
        $this->assertSame('staff notes', $fresh->resolution_notes);
        $this->assertEquals(850.00, (float) $fresh->final_amount);
        $this->assertSame(1, $f['origin']->incidents()->where('id', $incident->id)->count());
        $this->assertSame(1, $f['origin']->discounts()->where('id', $discount->id)->count());
    }

    /** @test */
    public function it_reports_advance_not_found_for_an_origin_without_advance(): void
    {
        $f = $this->makeAdvance();
        $f['header']->delete();

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['res' => false, 'code' => 'ADVANCE_NOT_FOUND']);
    }

    /** @test */
    public function it_returns_404_not_found_for_an_unknown_refrend(): void
    {
        $this->revert(999999)
            ->assertStatus(404)
            ->assertJson(['res' => false, 'code' => 'NOT_FOUND']);
    }

    // ---------------------------------------------------------------- R2

    /**
     * @test
     * @dataProvider invalidReasons
     */
    public function it_rejects_an_invalid_reason_without_deleting_anything(?string $reason): void
    {
        $f = $this->makeAdvance();

        $this->revert($f['origin']->id, $reason)
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertAdvanceIntact($f);
    }

    public function invalidReasons(): array
    {
        return [
            'missing'            => [null],
            'nine characters'    => ['123456789'],
            'blank'              => ['          '],
            'padded to nine'     => ['   123456789   '],
            'five hundred one'   => [str_repeat('a', 501)],
        ];
    }

    /** @test */
    public function it_accepts_boundary_reasons_and_trims_the_stored_note(): void
    {
        $f = $this->makeAdvance();

        $this->revert($f['origin']->id, '  ' . str_repeat('a', 10) . '  ')->assertStatus(200);

        $this->assertSame(
            str_repeat('a', 10),
            ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_REVERTED')->sole()->notes
        );

        $g = $this->makeAdvance();
        $this->revert($g['origin']->id, str_repeat('b', 500))->assertStatus(200);
    }

    // ---------------------------------------------------------------- R3

    /** @test */
    public function it_blocks_a_paid_origin(): void
    {
        $f = $this->makeAdvance(['status' => 'PAID']);

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['res' => false, 'code' => 'ADVANCE_ORIGIN_PAID']);

        $this->assertSame(1, ScholarshipAdvancePayment::count());
        $this->assertSame(3, ScholarshipAdvancePaymentMonth::count());
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_REVERTED')->count());
    }

    /** @test */
    public function it_blocks_a_locked_origin(): void
    {
        $f = $this->makeAdvance(['locked_at' => now()]);

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['res' => false, 'code' => 'ADVANCE_ORIGIN_LOCKED']);

        $this->assertSame(3, ScholarshipAdvancePaymentMonth::count());
    }

    /** @test */
    public function it_blocks_a_settled_month(): void
    {
        $f = $this->makeAdvance();
        $f['months'][1]->update(['status' => 'REACHED']);

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['res' => false, 'code' => 'ADVANCE_MONTH_SETTLED']);

        $this->assertSame(1, ScholarshipAdvancePayment::count());
        $this->assertSame(3, ScholarshipRefrend::where('created_via', 'ADVANCE_PAYMENT')->count());
    }

    /** @test */
    public function it_blocks_a_telmex_covered_future(): void
    {
        $f        = $this->makeAdvance();
        $coverage = TelmexCoverage::create([
            'user_id'                        => $f['user']->id,
            'scholarship_type_at_activation' => ScholarshipType::TELMEX->value,
            'start_period'                   => '2026-01-01',
            'status'                         => 'ACTIVA',
        ]);
        $f['futures'][2]->update(['snapshot_telmex_coverage_id' => $coverage->id]);

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['res' => false, 'code' => 'ADVANCE_FUTURE_TELMEX_COVERED']);

        $this->assertSame(3, ScholarshipRefrend::where('created_via', 'ADVANCE_PAYMENT')->count());
    }

    /** @test */
    public function it_blocks_a_future_that_is_not_pristine(): void
    {
        $f = $this->makeAdvance();
        $f['futures'][0]->update(['resolution_type' => 'SIN_ESPECIFICAR']);

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['res' => false, 'code' => 'ADVANCE_FUTURE_NOT_PRISTINE']);

        $this->assertSame(3, ScholarshipRefrend::where('created_via', 'ADVANCE_PAYMENT')->count());
    }

    /** @test */
    public function it_tolerates_the_automatic_profile_discount_incident_on_a_future(): void
    {
        $f = $this->makeAdvance();
        $f['futures'][0]->incidents()->create([
            'incident_category' => 'ACADEMICO',
            'incident_type'     => 'DESCUENTO_PERFIL',
            'description'       => 'Descuento de perfil.',
            'priority'          => 'LOW',
            'created_by_id'     => null,
        ]);

        $this->revert($f['origin']->id)->assertStatus(200);
    }

    /**
     * @test
     * @dataProvider cycleClosedStates
     */
    public function it_answers_409_when_the_cycle_was_cut(string $headerStatus, string $monthStatus): void
    {
        $f = $this->makeAdvance();
        $f['header']->update(['status' => $headerStatus]);
        $f['months'][0]->update(['status' => $monthStatus]);

        $this->revert($f['origin']->id)
            ->assertStatus(409)
            ->assertJson(['res' => false, 'code' => 'ADVANCE_CYCLE_CLOSED']);

        $this->assertSame(3, ScholarshipRefrend::where('created_via', 'ADVANCE_PAYMENT')->count());
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_REVERTED')->count());
    }

    public function cycleClosedStates(): array
    {
        return [
            'cancelled header'           => ['CANCELLED', 'CANCELLED'],
            'partially cancelled header' => ['PARTIALLY_CANCELLED', 'CANCELLED'],
        ];
    }

    /** @test */
    public function it_answers_409_when_the_user_cycle_is_closed(): void
    {
        $f = $this->makeAdvance();
        ScholarshipProfile::where('user_id', $f['user']->id)->first()->update([
            'cycle_closed_period'     => '2026-08-01',
            'cycle_closed_refrend_id' => $f['origin']->id,
        ]);

        $this->revert($f['origin']->id)
            ->assertStatus(409)
            ->assertJson(['code' => 'ADVANCE_CYCLE_CLOSED']);
    }

    // ---------------------------------------------------------------- R4

    /** @test */
    public function it_reports_state_changed_when_a_month_changes_between_read_and_lock(): void
    {
        $f = $this->makeAdvance();

        $this->swapActionWith(function () use ($f) {
            // Same set of months but one status flipped after the snapshot.
            DB::table('scholarship_advance_payment_months')
                ->where('id', $f['months'][0]->id)
                ->update(['status' => 'REACHED']);
        });

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['res' => false, 'code' => 'ADVANCE_STATE_CHANGED']);

        $this->assertSame(1, ScholarshipAdvancePayment::count());
        $this->assertSame(3, ScholarshipRefrend::where('created_via', 'ADVANCE_PAYMENT')->count());
    }

    /** @test */
    public function it_reports_state_changed_when_the_header_disappears_between_read_and_lock(): void
    {
        $f = $this->makeAdvance();

        $this->swapActionWith(function () use ($f) {
            ScholarshipAdvancePayment::whereKey($f['header']->id)->delete();
        });

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['code' => 'ADVANCE_STATE_CHANGED']);
    }

    /** @test */
    public function it_reports_state_changed_when_the_month_set_changes_between_read_and_lock(): void
    {
        $f = $this->makeAdvance();

        $this->swapActionWith(function () use ($f) {
            // A month vanishes (its future refrend deleted elsewhere).
            ScholarshipRefrend::whereKey($f['futures'][2]->id)->delete();
        });

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['code' => 'ADVANCE_STATE_CHANGED']);

        $this->assertSame(2, ScholarshipAdvancePaymentMonth::count());
    }

    /** @test */
    public function it_reports_the_specific_code_when_the_origin_gets_paid_before_the_lock(): void
    {
        $f = $this->makeAdvance();

        $this->swapActionWith(function () use ($f) {
            ScholarshipRefrend::whereKey($f['origin']->id)->update(['locked_at' => now()]);
        });

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['code' => 'ADVANCE_ORIGIN_LOCKED']);

        $this->assertSame(1, ScholarshipAdvancePayment::count());
    }

    /** @test */
    public function it_reports_the_specific_code_when_a_future_gets_approved_before_the_lock(): void
    {
        $f = $this->makeAdvance();

        $this->swapActionWith(function () use ($f) {
            ScholarshipRefrend::whereKey($f['futures'][1]->id)->update(['resolution_type' => 'SIN_ESPECIFICAR']);
        });

        $this->revert($f['origin']->id)
            ->assertStatus(422)
            ->assertJson(['code' => 'ADVANCE_FUTURE_NOT_PRISTINE']);

        $this->assertSame(3, ScholarshipRefrend::where('created_via', 'ADVANCE_PAYMENT')->count());
    }

    /** @test */
    public function it_acquires_locks_in_origin_futures_header_months_order(): void
    {
        $f         = $this->makeAdvance();
        $recording = false;
        $selects   = [];

        $this->swapActionWith(function () use (&$recording) {
            $recording = true;
        });
        DB::listen(function ($query) use (&$recording, &$selects) {
            if ($recording && stripos($query->sql, 'select') === 0) {
                $selects[] = $query->sql;
            }
        });

        $this->revert($f['origin']->id)->assertStatus(200);

        $position = function (string $table, int $from = 0) use ($selects): int {
            foreach ($selects as $i => $sql) {
                if ($i >= $from && str_contains($sql, '"' . $table . '"')) {
                    return $i;
                }
            }
            $this->fail("No select on {$table} after position {$from}.");
        };

        $origin  = $position('scholarship_refrends');
        $futures = $position('scholarship_refrends', $origin + 1);
        $header  = $position('scholarship_advance_payments', $futures + 1);
        $months  = $position('scholarship_advance_payment_months', $header + 1);

        $this->assertTrue($origin < $futures && $futures < $header && $header < $months);
        $this->assertStringContainsString('"id" in', strtolower($selects[$futures]));
    }

    // ---------------------------------------------------------------- FK audit

    /** @test */
    public function it_leaves_no_orphan_rows_after_deleting_futures_and_header(): void
    {
        $f         = $this->makeAdvance();
        $futureIds = array_map(fn ($r) => $r->id, $f['futures']);

        foreach ($f['futures'] as $future) {
            $future->incidents()->create([
                'incident_category' => 'ACADEMICO',
                'incident_type'     => 'DESCUENTO_PERFIL',
                'description'       => 'Descuento de perfil.',
                'priority'          => 'LOW',
                'created_by_id'     => null,
            ]);
            $future->discounts()->create([
                'discount_type'       => 'RETARDOS',
                'discount_percentage' => 10,
                'description'         => 'auto',
            ]);
            ScholarshipRefrendLog::create([
                'scholarship_refrend_id' => $future->id,
                'action'                 => 'GENERATED',
                'performed_by_id'        => $this->admin->id,
            ]);
        }

        $this->revert($f['origin']->id)->assertStatus(200);

        foreach ([
            ['scholarship_refrend_logs', 'scholarship_refrend_id'],
            ['scholarship_refrend_incidents', 'scholarship_refrend_id'],
            ['scholarship_refrend_discounts', 'scholarship_refrend_id'],
            ['scholarship_adjustments', 'scholarship_refrend_id'],
            ['scholarship_withholdings', 'origin_refrend_id'],
            ['scholarship_withholding_payments', 'applied_refrend_id'],
            ['scholarship_advance_payment_months', 'refrend_id'],
        ] as [$table, $column]) {
            $this->assertSame(
                0,
                DB::table($table)->whereIn($column, $futureIds)->count(),
                "Orphan rows left in {$table}.{$column}"
            );
        }

        $this->assertSame(0, DB::table('scholarship_advance_payments')->count());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    // ---------------------------------------------------------------- R5

    /** @test */
    public function it_allows_recording_a_new_advance_after_a_revert(): void
    {
        $f = $this->makeAdvance();

        $this->revert($f['origin']->id)->assertStatus(200);

        $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-refrends/{$f['origin']->id}/advance-payment", [
                'months' => [['year' => 2026, 'month' => 10], ['year' => 2026, 'month' => 11]],
                'cause'  => 'Nuevo viaje',
            ])
            ->assertStatus(200)
            ->assertJson(['res' => true]);

        $this->assertSame(1, ScholarshipAdvancePayment::count());
        $this->assertSame(2, ScholarshipAdvancePaymentMonth::count());
    }

    // ---------------------------------------------------------------- gate

    /** @test */
    public function it_requires_an_admin_user(): void
    {
        $f       = $this->makeAdvance();
        $becario = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $this->actingAs($becario)
            ->postJson($this->url($f['origin']->id), ['reason' => self::REASON])
            ->assertStatus(403);

        $this->assertAdvanceIntact($f);
    }

    /** @test */
    public function it_rejects_a_non_numeric_refrend_segment(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-refrends/abc/advance-payment/revert', ['reason' => self::REASON])
            ->assertStatus(404);
    }

    /**
     * Rebinds the action with a subclass whose afterSnapshot() seam runs the
     * given callback: simulates a concurrent change between the unlocked read
     * and the row locks.
     */
    private function swapActionWith(callable $hook): void
    {
        $this->app->bind(RevertAdvancePaymentAction::class, function ($app) use ($hook) {
            return new class (
                $app->make(\App\Services\ScholarshipLoggingService::class),
                $app->make(\App\Services\Scholarship\AdvanceRevertEligibility::class),
                $hook
            ) extends RevertAdvancePaymentAction {
                public function __construct($logging, $eligibility, private $hook)
                {
                    parent::__construct($logging, $eligibility);
                }

                protected function afterSnapshot(): void
                {
                    ($this->hook)();
                }
            };
        });
    }
}
