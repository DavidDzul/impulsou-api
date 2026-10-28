<?php

namespace Tests\Feature;

use App\Actions\Scholarship\BulkPayAction;
use App\Enums\PaymentBatchType;
use App\Enums\PaymentMethod;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Generation;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/pago-cheque PR2 (spec R2.4-R2.6, R6.1, R7): the controller splits cheque
 * rows from transfer rows server-side so the transfer batch flow, the bank
 * file and "Pagar todos" never see a cheque.
 */
class ScholarshipPaymentChequePartitionTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
    }

    private function makeRefrend(PaymentMethod $method, array $overrides = [], string $name = 'Becario'): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => self::CAMPUS,
            'active'     => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
        ]);

        if ($method === PaymentMethod::CHEQUE) {
            ScholarshipPaymentData::factory()->cheque()->create(['user_id' => $user->id]);
        } else {
            ScholarshipPaymentData::factory()->create(['user_id' => $user->id, 'account_number' => '0123456789', 'rfc' => 'PEPJ800101ABC']);
        }

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

    private function paidCheque(string $name = 'Paid cheque', array $overrides = []): ScholarshipRefrend
    {
        return $this->makeRefrend(PaymentMethod::CHEQUE, array_merge([
            'status'              => 'PAID',
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now(),
            'paid_payment_method' => PaymentMethod::CHEQUE->value,
            'cheque_number'       => 'A123',
        ], $overrides), $name);
    }

    private function index(string $type = 'NORMAL')
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/scholarship-payments?' . http_build_query([
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH, 'batch_type' => $type,
        ]));
    }

    private function process(int $count, string $total, string $type = 'NORMAL')
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/scholarship-payments/process', [
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH,
            'batch_type' => $type, 'expected_count' => $count, 'expected_total' => $total,
        ]);
    }

    // -- index ----------------------------------------------------------

    /** @test */
    public function index_keeps_rows_and_summary_transfer_only_and_adds_cheque_section(): void
    {
        $this->makeRefrend(PaymentMethod::TRANSFER, [], 'T1');
        $this->makeRefrend(PaymentMethod::TRANSFER, [], 'T2');
        $this->makeRefrend(PaymentMethod::TRANSFER, [], 'T3');
        $this->makeRefrend(PaymentMethod::CHEQUE, [], 'C1');
        $this->makeRefrend(PaymentMethod::CHEQUE, [], 'C2');

        $response = $this->index()->assertOk();

        $this->assertSame(['T1', 'T2', 'T3'], array_column($response->json('data.rows'), 'snapshot_name'));
        $this->assertSame(3, $response->json('data.summary.total'));
        $this->assertSame('3000.00', $response->json('data.summary.total_amount'));
        $this->assertSame(['C1', 'C2'], array_column($response->json('data.cheque_rows'), 'snapshot_name'));
        $this->assertSame(2, $response->json('data.cheque_summary.total'));
        $this->assertSame('2000.00', $response->json('data.cheque_summary.ready_amount'));
    }

    /** @test */
    public function paid_cheque_does_not_block_summary_or_mark_the_transfer_batch_as_paid(): void
    {
        $this->makeRefrend(PaymentMethod::TRANSFER, [], 'T1');
        $this->paidCheque();

        $response = $this->index()->assertOk();

        $this->assertSame(0, $response->json('data.summary.blocking'));
        $this->assertFalse($response->json('data.batch.is_paid'));
        $this->assertNull($response->json('data.batch.batch_id'));
        $this->assertSame(1, $response->json('data.cheque_summary.paid'));
    }

    /** @test */
    public function only_paid_cheques_in_the_key_leave_the_batch_unpaid(): void
    {
        $this->paidCheque();

        $response = $this->index()->assertOk();

        $this->assertSame([], $response->json('data.rows'));
        $this->assertFalse($response->json('data.batch.is_paid'));
    }

    /** @test */
    public function batch_block_is_paid_from_transfers_only_when_a_cheque_is_still_unpaid(): void
    {
        $this->makeRefrend(PaymentMethod::TRANSFER);
        $this->makeRefrend(PaymentMethod::CHEQUE);
        $this->process(1, '1000.00')->assertOk();

        $response = $this->index()->assertOk();

        $this->assertTrue($response->json('data.batch.is_paid'));
        $this->assertCount(1, $response->json('data.cheque_rows'));
    }

    /** @test */
    public function cert_unid_cheques_split_by_the_same_batch_type_filter(): void
    {
        $cheque = $this->makeRefrend(PaymentMethod::CHEQUE, [], 'C cert');
        ScholarshipProfile::create(['user_id' => $cheque->user_id, 'is_cert_unid' => true]);

        $this->assertSame([], $this->index('NORMAL')->json('data.cheque_rows'));
        $this->assertSame(['C cert'], array_column($this->index('CERT_UNID')->json('data.cheque_rows'), 'snapshot_name'));
    }

    // -- process --------------------------------------------------------

    /** @test */
    public function process_succeeds_with_a_paid_cheque_in_the_key_and_leaves_it_untouched(): void
    {
        $this->makeRefrend(PaymentMethod::TRANSFER, [], 'T1');
        $this->makeRefrend(PaymentMethod::TRANSFER, [], 'T2');
        $cheque = $this->paidCheque();

        $response = $this->process(2, '2000.00');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.rows'));
        $this->assertSame(2, ScholarshipPaymentBatch::first()->refrend_count);
        $cheque->refresh();
        $this->assertNull($cheque->payment_batch_id);
        $this->assertSame('PAID', $cheque->status->value);
    }

    /** @test */
    public function process_ignores_unpaid_and_unready_cheques_and_never_pays_them(): void
    {
        $this->makeRefrend(PaymentMethod::TRANSFER);
        $ready   = $this->makeRefrend(PaymentMethod::CHEQUE);
        $blocked = $this->makeRefrend(PaymentMethod::CHEQUE, ['workflow_status' => 'EN_REVISION']);

        $this->process(1, '1000.00')->assertOk();

        $this->assertSame('DRAFT', $ready->fresh()->status->value);
        $this->assertNull($ready->fresh()->payment_batch_id);
        $this->assertSame('DRAFT', $blocked->fresh()->status->value);
    }

    /** @test */
    public function process_returns_422_empty_batch_when_only_cheques_exist(): void
    {
        $this->makeRefrend(PaymentMethod::CHEQUE);

        $response = $this->process(0, '0.00');

        $response->assertStatus(422);
        $this->assertSame('EMPTY_BATCH', $response->json('code'));
        $this->assertSame(0, ScholarshipPaymentBatch::count());
    }

    /** @test */
    public function bank_file_never_contains_a_cheque_row(): void
    {
        $transfer = $this->makeRefrend(PaymentMethod::TRANSFER, [], 'T1');
        $this->paidCheque('Cheque person');

        $batchId = $this->process(1, '1000.00')->json('data.batch_id');

        $summary = $this->actingAs($this->admin)->getJson("/api/admin/scholarship-payments/batches/{$batchId}/export/summary");
        $summary->assertOk();
        $this->assertSame(1, $summary->json('data.count'));

        $file = $this->actingAs($this->admin)->get("/api/admin/scholarship-payments/batches/{$batchId}/export");
        $file->assertOk();
        $this->assertStringNotContainsString('CHEQUE PERSON', strtoupper($file->streamedContent()));
        $this->assertNotNull($transfer->fresh()->payment_batch_id);
    }

    /** @test */
    public function process_batch_totals_only_count_rows_actually_paid_when_a_becario_flips_to_cheque_mid_flight(): void
    {
        $this->makeRefrend(PaymentMethod::TRANSFER, [], 'T1');
        $flipping = $this->makeRefrend(PaymentMethod::TRANSFER, ['final_amount' => 500.00], 'T2');
        $this->makeRefrend(PaymentMethod::TRANSFER, [], 'T3');

        // Simulates the becario's payment method changing to CHEQUE after the
        // rows were read and before BulkPayAction takes the row locks.
        $this->app->bind(BulkPayAction::class, function ($app) use ($flipping) {
            $inner = new BulkPayAction(
                $app->make(\App\Services\Scholarship\PaymentReadinessEvaluator::class),
                $app->make(\App\Services\ScholarshipLoggingService::class)
            );

            return new class($inner, $flipping->user_id) extends BulkPayAction {
                public function __construct(private BulkPayAction $inner, private int $userId)
                {
                }

                public function execute(array $ids, int $userId, ?\App\Models\ScholarshipPaymentBatch $batch = null): array
                {
                    ScholarshipPaymentData::where('user_id', $this->userId)->update(['payment_method' => PaymentMethod::CHEQUE->value]);

                    return $this->inner->execute($ids, $userId, $batch);
                }
            };
        });

        $response = $this->process(3, '2500.00');

        $response->assertOk();
        $batch = ScholarshipPaymentBatch::findOrFail($response->json('data.batch_id'));
        $this->assertSame(2, (int) $batch->refrend_count);
        $this->assertSame('2000.00', number_format((float) $batch->total_amount, 2, '.', ''));
        $this->assertNull($flipping->fresh()->payment_batch_id);
    }

    // -- legacy bulk/pay route -------------------------------------------

    /** @test */
    public function legacy_bulk_pay_route_skips_cheques_and_pays_transfers(): void
    {
        $transfer = $this->makeRefrend(PaymentMethod::TRANSFER);
        $cheque   = $this->makeRefrend(PaymentMethod::CHEQUE);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/scholarship-refrends/bulk/pay', [
            'ids' => [$transfer->id, $cheque->id],
        ]);

        $response->assertOk();
        $this->assertSame('PAID', $transfer->fresh()->status->value);
        $this->assertSame('DRAFT', $cheque->fresh()->status->value);
        $this->assertNull($cheque->fresh()->locked_at);
    }

    // -- consumers --------------------------------------------------------

    /** @test */
    public function by_generation_report_counts_a_cheque_paid_refrend_as_paid(): void
    {
        $generation = Generation::create(['generation_name' => 'Gen', 'campus' => self::CAMPUS, 'generation_active' => true]);
        $this->paidCheque('Paid cheque', ['snapshot_generation_id' => $generation->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/scholarship-payments/by-generation?' . http_build_query([
            'generation_id' => $generation->id, 'period_year' => self::YEAR, 'period_month' => self::MONTH,
        ]));

        $response->assertOk();
        $this->assertSame(1, $response->json('data.summary.paid'));
        $this->assertSame('PAID', $response->json('data.rows.0.state'));
    }
}
