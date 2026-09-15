<?php

namespace Tests\Unit\Services\Scholarship;

use App\Models\ScholarshipPaymentBatch;
use App\Services\Scholarship\BankPaymentFileName;
use Tests\TestCase;

/**
 * Unit tests for BankPaymentFileName — one class, one FORMAT constant
 * (design D5, sdd/becario-payment-bank-file-export/design). Pure; batches
 * are plain in-memory models, never persisted.
 *
 * Campus slugging decision (design's open question, resolved here since
 * design did not specify a concrete algorithm): uppercase, accents
 * transliterated to ASCII via an explicit map (same "never iconv" spirit
 * as the PR2 serializer), then any remaining non-alphanumeric run
 * collapsed to a single underscore.
 */
class BankPaymentFileNameTest extends TestCase
{
    private function makeBatch(array $attributes): ScholarshipPaymentBatch
    {
        $batch = new ScholarshipPaymentBatch(array_diff_key($attributes, ['id' => true]));
        $batch->id = $attributes['id'];

        return $batch;
    }

    /** @test */
    public function generates_expected_filename_for_realistic_batch(): void
    {
        $batch = $this->makeBatch([
            'id'            => 42,
            'generation_id' => 5,
            'campus'        => 'MERIDA',
            'period_year'   => 2026,
            'period_month'  => 9,
        ]);

        $filename = BankPaymentFileName::forBatch($batch);

        $this->assertSame('PAGO_5_MERIDA_202609_42.TXT', $filename);
    }

    /** @test */
    public function period_month_is_zero_padded(): void
    {
        $batch = $this->makeBatch([
            'id'            => 1,
            'generation_id' => 1,
            'campus'        => 'MERIDA',
            'period_year'   => 2026,
            'period_month'  => 5,
        ]);

        $filename = BankPaymentFileName::forBatch($batch);

        $this->assertSame('PAGO_1_MERIDA_202605_1.TXT', $filename);
    }

    /** @test */
    public function campus_with_spaces_and_accents_is_slugged(): void
    {
        $batch = $this->makeBatch([
            'id'            => 8,
            'generation_id' => 3,
            'campus'        => 'Ciudad de México',
            'period_year'   => 2026,
            'period_month'  => 1,
        ]);

        $filename = BankPaymentFileName::forBatch($batch);

        $this->assertSame('PAGO_3_CIUDAD_DE_MEXICO_202601_8.TXT', $filename);
    }

    /** @test */
    public function campus_with_lowercase_accented_name_is_slugged(): void
    {
        $batch = $this->makeBatch([
            'id'            => 9,
            'generation_id' => 3,
            'campus'        => 'mérida',
            'period_year'   => 2026,
            'period_month'  => 1,
        ]);

        $filename = BankPaymentFileName::forBatch($batch);

        $this->assertSame('PAGO_3_MERIDA_202601_9.TXT', $filename);
    }

    /** @test */
    public function two_distinct_batches_never_collide(): void
    {
        $batchA = $this->makeBatch([
            'id'            => 1,
            'generation_id' => 5,
            'campus'        => 'MERIDA',
            'period_year'   => 2026,
            'period_month'  => 9,
        ]);

        $batchB = $this->makeBatch([
            'id'            => 2,
            'generation_id' => 5,
            'campus'        => 'MERIDA',
            'period_year'   => 2026,
            'period_month'  => 9,
        ]);

        $this->assertNotSame(
            BankPaymentFileName::forBatch($batchA),
            BankPaymentFileName::forBatch($batchB)
        );
    }

    /** @test */
    public function two_batches_differing_only_by_campus_never_collide(): void
    {
        $batchA = $this->makeBatch([
            'id'            => 3,
            'generation_id' => 5,
            'campus'        => 'MERIDA',
            'period_year'   => 2026,
            'period_month'  => 9,
        ]);

        $batchB = $this->makeBatch([
            'id'            => 3,
            'generation_id' => 5,
            'campus'        => 'CAMPECHE',
            'period_year'   => 2026,
            'period_month'  => 9,
        ]);

        $this->assertNotSame(
            BankPaymentFileName::forBatch($batchA),
            BankPaymentFileName::forBatch($batchB)
        );
    }
}
