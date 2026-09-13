<?php

namespace Tests\Unit;

use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers design D4 / task 1.5 (becario-payment-file-generation PR1): the
 * persisted batch entity, its fillable surface, and the refrends()/
 * processedBy() relations that give the deferred bank-file serializer and
 * a future "historial de pagos" view a stable handle on the exact set of
 * refrends that went into a given payment run.
 */
class ScholarshipPaymentBatchModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeBatch(User $admin, array $overrides = []): ScholarshipPaymentBatch
    {
        return ScholarshipPaymentBatch::create(array_merge([
            'generation_id'   => 1,
            'campus'          => 'CDMX',
            'period_year'     => 2026,
            'period_month'    => 9,
            'refrend_count'   => 1,
            'total_amount'    => 100.00,
            'processed_by_id' => $admin->id,
            'processed_at'    => now(),
        ], $overrides));
    }

    /** @test */
    public function table_name_is_explicitly_scholarship_payment_batches(): void
    {
        $this->assertSame('scholarship_payment_batches', (new ScholarshipPaymentBatch())->getTable());
    }

    /** @test */
    public function fillable_covers_the_batch_key_and_totals(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'generation_id',
                'campus',
                'period_year',
                'period_month',
                'refrend_count',
                'total_amount',
                'processed_by_id',
                'processed_at',
            ],
            (new ScholarshipPaymentBatch())->getFillable()
        );
    }

    /** @test */
    public function processed_by_relation_resolves_the_admin_user(): void
    {
        $admin = User::factory()->create();
        $batch = $this->makeBatch($admin);

        $this->assertTrue($batch->processedBy->is($admin));
    }

    /** @test */
    public function refrends_relation_resolves_every_refrend_stamped_with_this_batch_id(): void
    {
        $admin = User::factory()->create();
        $batch = $this->makeBatch($admin);

        $becario = User::factory()->create();

        $refrendId = DB::table('scholarship_refrends')->insertGetId([
            'user_id'          => $becario->id,
            'period_year'      => 2026,
            'period_month'     => 9,
            'base_amount'      => 100.00,
            'final_amount'     => 100.00,
            'snapshot_name'    => 'Test Becario',
            'payment_batch_id' => $batch->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->assertTrue($batch->refrends->pluck('id')->contains($refrendId));
    }

    /** @test */
    public function refrends_relation_is_a_has_many_and_processed_by_is_a_belongs_to(): void
    {
        $batch = new ScholarshipPaymentBatch();

        $this->assertInstanceOf(HasMany::class, $batch->refrends());
        $this->assertInstanceOf(BelongsTo::class, $batch->processedBy());
    }
}
