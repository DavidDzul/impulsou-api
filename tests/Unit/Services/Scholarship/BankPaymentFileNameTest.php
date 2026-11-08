<?php

namespace Tests\Unit\Services\Scholarship;

use App\Enums\BankFileConcept;
use App\Enums\PaymentBatchType;
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

        $filename = BankPaymentFileName::forBatch($batch, BankFileConcept::BECA);

        $this->assertSame('PAGO_MERIDA_202609_42_BECA.TXT', $filename);
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

        $filename = BankPaymentFileName::forBatch($batch, BankFileConcept::BECA);

        $this->assertSame('PAGO_MERIDA_202605_1_BECA.TXT', $filename);
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

        $filename = BankPaymentFileName::forBatch($batch, BankFileConcept::BECA);

        $this->assertSame('PAGO_CIUDAD_DE_MEXICO_202601_8_BECA.TXT', $filename);
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

        $filename = BankPaymentFileName::forBatch($batch, BankFileConcept::BECA);

        $this->assertSame('PAGO_MERIDA_202601_9_BECA.TXT', $filename);
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
            BankPaymentFileName::forBatch($batchA, BankFileConcept::BECA),
            BankPaymentFileName::forBatch($batchB, BankFileConcept::BECA)
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
            BankPaymentFileName::forBatch($batchA, BankFileConcept::BECA),
            BankPaymentFileName::forBatch($batchB, BankFileConcept::BECA)
        );
    }

    // ── batch_type segment (sdd/pagos-lote-pago-adelantado, amendment #2) ──
    //
    // NORMAL must stay BYTE-IDENTICAL to the current filename (no segment at
    // all) — every test above already locks that implicitly (none of them
    // set batch_type, so it is null). These tests lock it EXPLICITLY for a
    // batch that does carry an explicit NORMAL batch_type, and add the new
    // CERT_UNID behavior.

    /** @test */
    public function an_explicit_normal_batch_type_produces_the_same_filename_as_no_batch_type_at_all(): void
    {
        $batch = $this->makeBatch([
            'id'            => 42,
            'generation_id' => 5,
            'campus'        => 'MERIDA',
            'period_year'   => 2026,
            'period_month'  => 9,
            'batch_type'    => PaymentBatchType::NORMAL->value,
        ]);

        $filename = BankPaymentFileName::forBatch($batch, BankFileConcept::BECA);

        $this->assertSame('PAGO_MERIDA_202609_42_BECA.TXT', $filename, 'NORMAL must never add a type segment — same segment order as the pre-change filename (only the concept suffix is new).');
    }

    /** @test */
    public function a_cert_unid_batch_type_adds_the_certunid_segment(): void
    {
        $batch = $this->makeBatch([
            'id'            => 42,
            'generation_id' => 5,
            'campus'        => 'MERIDA',
            'period_year'   => 2026,
            'period_month'  => 9,
            'batch_type'    => PaymentBatchType::CERT_UNID->value,
        ]);

        $filename = BankPaymentFileName::forBatch($batch, BankFileConcept::BECA);

        $this->assertSame('PAGO_MERIDA_CERTUNID_202609_42_BECA.TXT', $filename);
    }

    /** @test */
    public function apoyo_filename_swaps_only_the_concept_suffix_for_both_batch_types(): void
    {
        $attributes = ['id' => 42, 'generation_id' => 5, 'campus' => 'MERIDA', 'period_year' => 2026, 'period_month' => 9];
        $normal     = $this->makeBatch($attributes + ['batch_type' => PaymentBatchType::NORMAL->value]);
        $certUnid   = $this->makeBatch($attributes + ['batch_type' => PaymentBatchType::CERT_UNID->value]);

        $this->assertSame('PAGO_MERIDA_202609_42_APOYO.TXT', BankPaymentFileName::forBatch($normal, BankFileConcept::APOYO));
        $this->assertSame('PAGO_MERIDA_CERTUNID_202609_42_APOYO.TXT', BankPaymentFileName::forBatch($certUnid, BankFileConcept::APOYO));
    }

    /** @test */
    public function the_two_concepts_of_one_batch_never_collide(): void
    {
        $batch = $this->makeBatch(['id' => 7, 'generation_id' => 5, 'campus' => 'MERIDA', 'period_year' => 2026, 'period_month' => 9]);

        $this->assertNotSame(
            BankPaymentFileName::forBatch($batch, BankFileConcept::BECA),
            BankPaymentFileName::forBatch($batch, BankFileConcept::APOYO)
        );
    }

    /** @test */
    public function two_batches_sharing_every_other_field_never_collide_when_only_batch_type_differs(): void
    {
        $normal = $this->makeBatch([
            'id'            => 7,
            'generation_id' => 5,
            'campus'        => 'MERIDA',
            'period_year'   => 2026,
            'period_month'  => 9,
            'batch_type'    => PaymentBatchType::NORMAL->value,
        ]);
        $certUnid = $this->makeBatch([
            'id'            => 7,
            'generation_id' => 5,
            'campus'        => 'MERIDA',
            'period_year'   => 2026,
            'period_month'  => 9,
            'batch_type'    => PaymentBatchType::CERT_UNID->value,
        ]);

        $this->assertNotSame(
            BankPaymentFileName::forBatch($normal, BankFileConcept::BECA),
            BankPaymentFileName::forBatch($certUnid, BankFileConcept::BECA)
        );
    }
}
