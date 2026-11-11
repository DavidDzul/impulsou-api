<?php

namespace Tests\Feature;

use App\Enums\PaymentBatchType;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Bank;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\ScholarshipRefrendManualPayment;
use App\Models\User;
use App\Services\Scholarship\ManualPaymentConceptAmounts;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/catalogo-bancos R7: mark / revert a transfer to a non-BBVA bank that is
 * paid manually, one refrend at a time.
 */
class ScholarshipManualTransferPaymentEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;
    private const REASON = 'Transferencia rechazada por el banco';
    private const CLABE  = '072000000000000009';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
    }

    private function makeRefrend(array $spd = [], array $overrides = [], string $name = 'Becario'): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE', 'campus' => self::CAMPUS, 'active' => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
        ]);

        ScholarshipPaymentData::factory()->create(array_merge([
            'user_id'        => $user->id,
            'bank_id'        => Bank::where('code', '072')->value('id'),
            'bank_name'      => 'Banorte',
            'account_number' => self::CLABE,
        ], $spd));

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => $name,
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function chequeSpd(): array
    {
        return ['payment_method' => 'CHEQUE', 'bank_id' => null, 'bank_name' => null, 'account_number' => null];
    }

    /** Mark body for the default 1000.00 Beca-only fixture. */
    private function body(string $beca = 'REF-1', array $extra = []): array
    {
        return array_merge([
            'references'       => ['beca' => $beca],
            'expected_amounts' => ['beca' => '1000.00', 'apoyo' => '0.00'],
        ], $extra);
    }

    /** Fixture whose split has both concepts (1500 Beca / 200 Apoyo). */
    private function twoConceptOverrides(): array
    {
        return [
            'base_amount'           => 2000.00,
            'snapshot_monto_apoyo'  => 200.00,
            'snapshot_gross_amount' => 2200.00,
            'discount_amount'       => 500.00,
            'final_amount'          => 1700.00,
        ];
    }

    private function twoConceptBody(ScholarshipRefrend $refrend, string $beca = 'B-1', string $apoyo = 'A-1', array $extra = []): array
    {
        $amounts = $this->app->make(ManualPaymentConceptAmounts::class)->forRefrend($refrend->id);

        return array_merge([
            'references'       => ['beca' => $beca, 'apoyo' => $apoyo],
            'expected_amounts' => ['beca' => $amounts['beca'], 'apoyo' => $amounts['apoyo']],
        ], $extra);
    }

    private function children(ScholarshipRefrend $refrend)
    {
        return ScholarshipRefrendManualPayment::where('refrend_id', $refrend->id)->orderBy('concept')->get();
    }

    private function markPaid(ScholarshipRefrend $refrend, ?array $body = null, ?User $as = null)
    {
        $body ??= $this->body();

        return $this->actingAs($as ?? $this->admin)
            ->postJson("/api/admin/scholarship-payments/{$refrend->id}/manual-transfer-payment", $body);
    }

    private function revert(ScholarshipRefrend $refrend, array $body = ['reason' => self::REASON], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)
            ->postJson("/api/admin/scholarship-payments/{$refrend->id}/manual-transfer-payment/revert", $body);
    }

    /** @test */
    public function marking_paid_closes_the_refrend_with_the_manual_transfer_snapshot_and_logs_bank_and_clabe(): void
    {
        $refrend = $this->makeRefrend();

        $response = $this->markPaid($refrend, $this->body('  REF-1  ', ['paid_at' => '2026-05-10', 'notes' => 'SPEI']));
        $response
            ->assertOk()
            ->assertJsonPath('data.paid_payment_method', 'MANUAL_TRANSFER');

        $fresh = $refrend->fresh();
        $this->assertSame('PAID', $fresh->status->value);
        $this->assertSame('CLOSED', $fresh->workflow_status);
        $this->assertNotNull($fresh->locked_at);
        $this->assertNull($fresh->payment_batch_id);
        $this->assertSame('MANUAL_TRANSFER', $fresh->paid_payment_method->value);

        $rows = $this->children($refrend);
        $this->assertCount(1, $rows);
        $this->assertSame('beca', $rows[0]->concept);
        $this->assertSame('MANUAL_TRANSFER', (string) $rows[0]->getRawOriginal('method'));
        $this->assertSame('REF-1', $rows[0]->reference);
        $this->assertSame('1000.00', (string) $rows[0]->amount);
        $this->assertSame('2026-05-10', $rows[0]->paid_at->toDateString());
        $this->assertSame('SPEI', $rows[0]->notes);
        $this->assertSame($this->admin->id, $rows[0]->created_by_id);
        $data = $response->json('data');
        $this->assertSame([[
            'concept' => 'beca', 'method' => 'MANUAL_TRANSFER', 'reference' => 'REF-1',
            'amount' => '1000.00', 'paid_at' => '2026-05-10', 'notes' => 'SPEI',
        ]], $data['manual_payments']);
        foreach (['transfer_reference', 'transfer_paid_at', 'transfer_notes'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $data);
        }

        $log = ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
            ->where('action', 'MANUAL_TRANSFER_PAID')->firstOrFail();
        $this->assertSame('072', $log->new_values['bank_code']);
        $this->assertSame('Banorte', $log->new_values['bank_name']);
        $this->assertSame(self::CLABE, $log->new_values['clabe']);
        $this->assertArrayNotHasKey('transfer_reference', $log->new_values);
        $this->assertSame('REF-1', $log->new_values['manual_payments'][0]['reference']);
        $this->assertSame('beca', $log->new_values['manual_payments'][0]['concept']);
    }

    /** @test */
    public function a_two_concept_refrend_writes_both_rows_atomically(): void
    {
        $refrend = $this->makeRefrend([], $this->twoConceptOverrides());

        $this->markPaid($refrend, $this->twoConceptBody($refrend))->assertOk();

        $rows = $this->children($refrend);
        $this->assertSame(['apoyo', 'beca'], $rows->pluck('concept')->all());
        $this->assertSame(['A-1', 'B-1'], $rows->pluck('reference')->all());
        $this->assertEqualsWithDelta((float) $refrend->fresh()->final_amount, (float) $rows->sum('amount'), 0.001);
    }

    /** @test */
    public function a_zero_concept_writes_no_row_and_rejects_its_reference(): void
    {
        $refrend = $this->makeRefrend();

        $this->markPaid($refrend, $this->body('B-1', ['references' => ['beca' => 'B-1', 'apoyo' => 'A-1']]))
            ->assertStatus(422)->assertJsonValidationErrors('references.apoyo');
        $this->assertSame(0, $this->children($refrend)->count());
        $this->assertSame('DRAFT', $refrend->fresh()->status->value);
    }

    /** @test */
    public function a_blank_reference_for_a_positive_concept_is_rejected_without_rows(): void
    {
        $refrend = $this->makeRefrend([], $this->twoConceptOverrides());

        $this->markPaid($refrend, $this->twoConceptBody($refrend, 'B-1', '   '))
            ->assertStatus(422)->assertJsonValidationErrors('references.apoyo');
        $this->assertSame(0, $this->children($refrend)->count());
        $this->assertSame('DRAFT', $refrend->fresh()->status->value);
    }

    /** @test */
    public function beca_and_apoyo_references_must_differ_ignoring_case_and_whitespace(): void
    {
        $refrend = $this->makeRefrend([], $this->twoConceptOverrides());

        $this->markPaid($refrend, $this->twoConceptBody($refrend, 'Same-1', ' same-1 '))
            ->assertStatus(422)->assertJsonValidationErrors('references.apoyo');
        $this->assertSame(0, $this->children($refrend)->count());
    }

    /** @test */
    public function stale_expected_amounts_are_a_409_and_nothing_is_written(): void
    {
        $refrend = $this->makeRefrend([], $this->twoConceptOverrides());

        $this->markPaid($refrend, $this->twoConceptBody($refrend, 'B-1', 'A-1', [
            'expected_amounts' => ['beca' => '1000.00', 'apoyo' => '700.00'],
        ]))->assertStatus(409)->assertJsonPath('code', 'CONCEPT_SPLIT_CHANGED');
        $this->assertSame(0, $this->children($refrend)->count());
        $this->assertSame('DRAFT', $refrend->fresh()->status->value);
    }

    /** @test */
    public function a_failure_on_the_second_insert_rolls_everything_back(): void
    {
        $refrend = $this->makeRefrend([], $this->twoConceptOverrides());
        $calls = 0;
        ScholarshipRefrendManualPayment::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $this->markPaid($refrend, $this->twoConceptBody($refrend));
        } catch (\Throwable) {
            // the handler may render or rethrow; only the DB state matters
        }

        $this->assertSame(0, $this->children($refrend)->count());
        $this->assertSame('DRAFT', $refrend->fresh()->status->value);
        $this->assertNull($refrend->fresh()->paid_payment_method);
    }

    /** @test */
    public function a_unique_index_violation_is_mapped_to_transfer_reference_taken(): void
    {
        $refrend = $this->makeRefrend();
        $other   = $this->makeRefrend();
        // A concurrent mark commits the same reference right after the pre-check.
        ScholarshipRefrendManualPayment::creating(function () use ($other) {
            DB::table('scholarship_refrend_manual_payments')->insert([
                'refrend_id' => $other->id, 'concept' => 'beca', 'method' => 'MANUAL_TRANSFER',
                'reference' => 'RACE', 'reference_normalized' => 'race', 'amount' => '1000.00',
                'paid_at' => '2026-05-10', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->markPaid($refrend, $this->body('Race'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSFER_REFERENCE_TAKEN')
            ->assertJsonPath('data.concept', 'beca');
        $this->assertSame(0, $this->children($refrend)->count());
    }

    /** @test */
    public function the_reference_is_required(): void
    {
        $refrend = $this->makeRefrend();

        $this->markPaid($refrend, $this->body('   '))
            ->assertStatus(422)
            ->assertJsonValidationErrors('references.beca');
        $this->assertSame('DRAFT', $refrend->fresh()->status->value);
    }

    /** @test */
    public function revert_fails_without_mutating_when_the_paid_log_is_missing(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend)->assertOk();
        ScholarshipRefrendLog::where('scholarship_refrend_id', $refrend->id)
            ->where('action', 'MANUAL_TRANSFER_PAID')->delete();

        $this->revert($refrend)->assertStatus(422)->assertJsonPath('code', 'TRANSFER_LOG_MISSING');

        $fresh = $refrend->fresh();
        $this->assertSame('PAID', $fresh->status->value);
        $this->assertSame('CLOSED', $fresh->workflow_status);
        $this->assertSame(1, $this->children($refrend)->count());
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'MANUAL_TRANSFER_REVERTED')->count());
    }

    /** @test */
    public function a_future_paid_date_is_rejected(): void
    {
        $this->markPaid($this->makeRefrend(), $this->body('X', ['paid_at' => now()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('paid_at');
    }

    /** @test */
    public function the_paid_date_defaults_to_today(): void
    {
        $refrend = $this->makeRefrend();

        $this->markPaid($refrend)->assertOk();

        $this->assertSame(now()->toDateString(), $this->children($refrend)[0]->paid_at->toDateString());
    }

    /** @test */
    public function a_reference_cannot_be_reused_among_manual_transfers_case_insensitively(): void
    {
        $this->markPaid($this->makeRefrend(), $this->body('Abc-1'))->assertOk();
        $second = $this->makeRefrend();

        $this->markPaid($second, $this->body(' abc-1 '))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSFER_REFERENCE_TAKEN')
            ->assertJsonPath('data.concept', 'beca');
        $this->assertSame('DRAFT', $second->fresh()->status->value);
        $this->assertSame(0, $this->children($second)->count());
    }

    /** @test */
    public function a_reference_is_free_again_after_a_revert(): void
    {
        $first = $this->makeRefrend();
        $this->markPaid($first, $this->body('R-9'))->assertOk();
        $this->revert($first)->assertOk();

        $this->markPaid($this->makeRefrend(), $this->body('R-9'))->assertOk();
    }

    /** Cheque mark body for the default 1000.00 Beca-only fixture. */
    private function chequeBody(string $reference): array
    {
        return [
            'references'       => ['beca' => $reference],
            'expected_amounts' => ['beca' => '1000.00', 'apoyo' => '0.00'],
        ];
    }

    /** @test */
    public function the_reference_namespace_is_separate_from_cheque_numbers(): void
    {
        $cheque = $this->makeRefrend($this->chequeSpd());
        $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-payments/{$cheque->id}/cheque-payment", $this->chequeBody('SAME-1'))
            ->assertOk();

        $this->markPaid($this->makeRefrend(), $this->body('SAME-1'))->assertOk();
    }

    /** @test */
    public function a_second_mark_is_a_409_already_paid(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend)->assertOk();

        $this->markPaid($refrend, $this->body('OTHER'))
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_PAID');
    }

    /** @test */
    public function a_bbva_cheque_or_bankless_becario_is_not_a_manual_transfer(): void
    {
        $bbva = $this->makeRefrend([
            'bank_id' => Bank::where('code', '012')->value('id'), 'bank_name' => 'BBVA', 'account_number' => '0123456789',
        ]);
        $cheque = $this->makeRefrend($this->chequeSpd());
        $none   = $this->makeRefrend(['bank_id' => null, 'bank_name' => null]);

        foreach ([$bbva, $cheque, $none] as $refrend) {
            $this->markPaid($refrend)->assertStatus(422)->assertJsonPath('code', 'NOT_MANUAL_TRANSFER_METHOD');
        }
    }

    /** @test */
    public function an_invalid_clabe_blocks_the_payment(): void
    {
        $refrend = $this->makeRefrend(['account_number' => '072000000000000001']);

        $this->markPaid($refrend)->assertStatus(422)->assertJsonPath('code', 'NOT_PAYABLE');
    }

    /** @test */
    public function an_unapproved_refrend_cannot_be_paid(): void
    {
        $refrend = $this->makeRefrend([], ['workflow_status' => 'PENDING']);

        $this->markPaid($refrend)->assertStatus(422)->assertJsonPath('code', 'NOT_PAYABLE');
    }

    /** @test */
    public function an_unknown_refrend_is_a_404(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/scholarship-payments/999999/manual-transfer-payment', $this->body('X'))
            ->assertStatus(404);
    }

    /** @test */
    public function the_cert_unid_segment_is_frozen_at_payment_time(): void
    {
        $refrend = $this->makeRefrend();
        ScholarshipProfile::factory()->create(['user_id' => $refrend->user_id, 'is_cert_unid' => true]);

        $this->markPaid($refrend)->assertOk();

        $this->assertSame(PaymentBatchType::CERT_UNID, $refrend->fresh()->paid_batch_type);
    }

    /** @test */
    public function revert_restores_the_prior_state_and_clears_the_transfer_fields(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend)->assertOk();

        $this->revert($refrend)->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.manual_payments', []);

        $fresh = $refrend->fresh();
        $this->assertSame('LISTO_PARA_PAGO', $fresh->workflow_status);
        $this->assertNull($fresh->locked_at);
        $this->assertNull($fresh->paid_payment_method);
        $this->assertNull($fresh->paid_batch_type);
        $this->assertSame(0, $this->children($refrend)->count());
        $this->assertSame(1, ScholarshipRefrendLog::where('action', 'MANUAL_TRANSFER_REVERTED')->count());
        $log = ScholarshipRefrendLog::where('action', 'MANUAL_TRANSFER_REVERTED')->firstOrFail();
        $this->assertSame('REF-1', $log->old_values['manual_payments'][0]['reference']);
        $this->assertSame([], $log->new_values['manual_payments']);
        $this->assertArrayNotHasKey('transfer_reference', $log->old_values);
        $this->markPaid($refrend)->assertOk();
    }

    /** @test */
    public function revert_deletes_both_rows_and_frees_both_references(): void
    {
        $refrend = $this->makeRefrend([], $this->twoConceptOverrides());
        $this->markPaid($refrend, $this->twoConceptBody($refrend))->assertOk();

        $this->revert($refrend)->assertOk();

        $this->assertSame(0, $this->children($refrend)->count());
        $second = $this->makeRefrend([], $this->twoConceptOverrides());
        $this->markPaid($second, $this->twoConceptBody($second))->assertOk();
    }

    /** @test */
    public function revert_rejects_a_refrend_not_paid_by_manual_transfer(): void
    {
        $this->revert($this->makeRefrend())
            ->assertStatus(422)->assertJsonPath('code', 'NOT_MANUAL_TRANSFER_PAID');
    }

    /** @test */
    public function each_revert_endpoint_ignores_the_other_payment_kind(): void
    {
        $cheque = $this->makeRefrend($this->chequeSpd());
        $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-payments/{$cheque->id}/cheque-payment", $this->chequeBody('C-1'))
            ->assertOk();
        $transfer = $this->makeRefrend();
        $this->markPaid($transfer)->assertOk();

        $this->revert($cheque)->assertStatus(422)->assertJsonPath('code', 'NOT_MANUAL_TRANSFER_PAID');
        $this->actingAs($this->admin)
            ->postJson("/api/admin/scholarship-payments/{$transfer->id}/cheque-payment/revert", ['reason' => self::REASON])
            ->assertStatus(422)->assertJsonPath('code', 'NOT_CHEQUE_PAID');
    }

    /** @test */
    public function revert_is_blocked_when_the_cycle_is_closed(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend)->assertOk();
        ScholarshipProfile::factory()->create(['user_id' => $refrend->user_id, 'cycle_closed_period' => '2026-06']);

        $this->revert($refrend)->assertStatus(409)->assertJsonPath('code', 'CYCLE_CLOSED');
    }

    /** @test */
    public function revert_requires_a_reason_of_at_least_ten_characters(): void
    {
        $refrend = $this->makeRefrend();
        $this->markPaid($refrend)->assertOk();

        $this->revert($refrend, ['reason' => 'corto'])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    /** @test */
    public function both_endpoints_require_the_process_payments_permission(): void
    {
        $refrend = $this->makeRefrend();
        $viewer  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        $this->markPaid($refrend, $this->body('X'), $viewer)->assertStatus(403);
        $this->revert($refrend, ['reason' => self::REASON], $viewer)->assertStatus(403);
    }

    /** @test */
    public function index_exposes_the_manual_transfer_section_with_its_summary(): void
    {
        $pending = $this->makeRefrend([], [], 'B pendiente');
        $paid    = $this->makeRefrend([], [], 'A pagado');
        $this->makeRefrend([
            'bank_id' => Bank::where('code', '012')->value('id'), 'bank_name' => 'BBVA', 'account_number' => '0123456789',
            'rfc' => 'PEPJ800101ABC',
        ], [], 'C bbva');
        $this->markPaid($paid, $this->body('REF-A'))->assertOk();
        // Drift the live amount after marking: the paid figures must stay frozen.
        $paid->refresh()->forceFill(['final_amount' => 400.00])->save();

        $response = $this->actingAs($this->admin)->getJson('/api/admin/scholarship-payments?' . http_build_query([
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH, 'batch_type' => 'NORMAL',
        ]))->assertOk();

        $rows = $response->json('data.manual_transfer_rows');
        $this->assertSame(['A pagado', 'B pendiente'], array_column($rows, 'snapshot_name'));
        $this->assertSame('MANUAL_TRANSFER', $rows[0]['payment_method']);
        $this->assertSame('072', $rows[1]['bank_code']);
        $this->assertSame(self::CLABE, $rows[1]['account_number']);
        $this->assertTrue($rows[1]['is_payable']);
        $this->assertSame($pending->id, $rows[1]['refrend_id']);

        $summary = $response->json('data.manual_transfer_summary');
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['ready']);
        $this->assertSame(1, $summary['paid']);
        $this->assertSame('1000.00', $summary['paid_amount']);
        $this->assertSame('1000.00', $summary['paid_beca_amount']);
        $this->assertSame('0.00', $summary['paid_apoyo_amount']);
        $this->assertSame('REF-A', $rows[0]['manual_payments'][0]['reference']);
        $this->assertSame('1000.00', $rows[0]['manual_payments'][0]['amount']);
        $this->assertSame([], $rows[1]['manual_payments']);
        $this->assertArrayNotHasKey('transfer_reference', $rows[0]);

        $this->assertSame(['C bbva'], array_column($response->json('data.rows'), 'snapshot_name'));
    }
}
