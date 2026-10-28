<?php

namespace Tests\Feature\Migrations;

use App\Enums\PaymentBatchType;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/cert-unid-historial PR1 (spec R1, R6): paid_batch_type column and the
 * cheque-paid backfill.
 */
class PaidBatchTypeOnScholarshipRefrendsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL_PATH = __DIR__ . '/../../../database/migrations/2026_10_28_200001_backfill_paid_batch_type_on_scholarship_refrends.php';

    private int $sequence = 0;

    private function backfill(): Migration
    {
        return require self::BACKFILL_PATH;
    }

    private function makeRefrend(?bool $flag, array $attributes = []): int
    {
        $user = User::factory()->create();

        if ($flag !== null) {
            ScholarshipProfile::factory()->create([
                'user_id'                  => $user->id,
                'advance_payment_eligible' => $flag,
            ]);
        }

        $now = now()->subDays(3)->toDateTimeString();

        return DB::table('scholarship_refrends')->insertGetId(array_merge([
            'user_id'         => $user->id,
            'period_year'     => 2030,
            'period_month'    => (++$this->sequence % 12) + 1,
            'refrend_type'    => 'NORMAL',
            'status'          => 'DRAFT',
            'workflow_status' => 'DRAFT',
            'base_amount'     => 2000,
            'final_amount'    => 2000,
            'snapshot_name'   => 'Test',
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $attributes));
    }

    private function chequePaid(?bool $flag, array $attributes = []): int
    {
        return $this->makeRefrend($flag, array_merge([
            'status'              => 'PAID',
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now()->toDateTimeString(),
            'paid_payment_method' => 'CHEQUE',
        ], $attributes));
    }

    private function stamp(int $id): ?string
    {
        return DB::table('scholarship_refrends')->where('id', $id)->value('paid_batch_type');
    }

    /** @test */
    public function the_column_exists_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('scholarship_refrends', 'paid_batch_type'));

        // Nullable: a row inserted without it reads back NULL. (The AFTER
        // position is MySQL-only; SQLite appends, so it is not asserted.)
        $id = $this->makeRefrend(null);
        $this->assertNull($this->stamp($id));
    }

    /** @test */
    public function the_model_casts_the_column_to_the_batch_type_enum(): void
    {
        $id = $this->makeRefrend(null, ['paid_batch_type' => 'CERT_UNID']);

        $this->assertSame(PaymentBatchType::CERT_UNID, ScholarshipRefrend::find($id)->paid_batch_type);
    }

    /** @test */
    public function backfill_stamps_only_cheque_paid_rows_from_the_current_flag(): void
    {
        $p1 = $this->chequePaid(true);
        $p2 = $this->chequePaid(false);
        $p3 = $this->chequePaid(null);

        $batch = ScholarshipPaymentBatch::create([
            'generation_id'   => 1,
            'campus'          => 'MERIDA',
            'period_year'     => 2030,
            'period_month'    => 1,
            'refrend_count'   => 1,
            'total_amount'    => '2000.00',
            'processed_by_id' => User::factory()->create()->id,
            'processed_at'    => now(),
        ]);
        $batchPaid = $this->makeRefrend(true, [
            'status'              => 'PAID',
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now()->toDateTimeString(),
            'paid_payment_method' => 'TRANSFER',
            'payment_batch_id'    => $batch->id,
        ]);
        $unpaid = $this->makeRefrend(true);

        $this->backfill()->up();

        $this->assertSame('CERT_UNID', $this->stamp($p1));
        $this->assertSame('NORMAL', $this->stamp($p2));
        $this->assertSame('NORMAL', $this->stamp($p3));
        $this->assertNull($this->stamp($batchPaid));
        $this->assertNull($this->stamp($unpaid));
    }

    /** @test */
    public function backfill_is_idempotent_and_never_rewrites_a_stamped_row(): void
    {
        $stamped = $this->chequePaid(false, ['paid_batch_type' => 'CERT_UNID']);
        $fresh   = $this->chequePaid(true);

        $this->backfill()->up();
        $this->backfill()->up();

        $this->assertSame('CERT_UNID', $this->stamp($stamped));
        $this->assertSame('CERT_UNID', $this->stamp($fresh));
    }
}
