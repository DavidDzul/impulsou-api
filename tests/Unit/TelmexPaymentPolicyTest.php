<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Support\Scholarship\TelmexPaymentPolicy;
use Tests\TestCase;

/**
 * Unit tests for TelmexPaymentPolicy (sdd/scholarship-telmex-iu-split,
 * design D1) — the single source of truth for every TELMEX payability rule.
 * Pure PHP, no DB.
 */
class TelmexPaymentPolicyTest extends TestCase
{
    // ── isBatchCandidate() — Filter A, per-object shape ─────────────────────

    /** @test */
    public function iu_is_always_a_candidate_regardless_of_increase(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::IU->value, null));
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::IU->value, 0));
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::IU->value, 500));
    }

    /** @test */
    public function telmex_iu_is_always_a_candidate_regardless_of_increase(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX_IU->value, null));
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX_IU->value, 0));
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX_IU->value, 500));
    }

    /** @test */
    public function pure_telmex_without_increase_is_not_a_candidate(): void
    {
        $this->assertFalse(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, null));
        $this->assertFalse(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, 0));
        $this->assertFalse(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, -10));
    }

    /** @test */
    public function pure_telmex_with_a_positive_increase_is_a_candidate(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, 500));
    }

    /** @test */
    public function null_type_is_treated_as_a_candidate(): void
    {
        // Defensive: a caller/fixture that omits the column entirely must
        // never accidentally block payment (never silently TELMEX).
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(null, null));
    }

    // ── D5: enum-cast/string asymmetry — same result for both input shapes ──

    /** @test */
    public function is_batch_candidate_accepts_a_raw_string_type(): void
    {
        $this->assertFalse(TelmexPaymentPolicy::isBatchCandidate('TELMEX', null));
    }

    /** @test */
    public function is_batch_candidate_accepts_an_enum_object_type(): void
    {
        $this->assertFalse(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX, null));
    }

    /** @test */
    public function is_batch_candidate_treats_string_and_enum_object_identically(): void
    {
        $stringResult = TelmexPaymentPolicy::isBatchCandidate('TELMEX', 500);
        $enumResult   = TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX, 500);

        $this->assertSame($stringResult, $enumResult);
        $this->assertTrue($stringResult);
    }

    // ── isExcludedFromBankFile() — Filter B ──────────────────────────────────

    /** @test */
    public function telmex_row_with_zero_total_to_pay_is_excluded(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isExcludedFromBankFile(ScholarshipType::TELMEX->value, '0.00'));
    }

    /** @test */
    public function telmex_row_with_negative_total_to_pay_is_excluded(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isExcludedFromBankFile(ScholarshipType::TELMEX->value, '-5.00'));
    }

    /** @test */
    public function telmex_row_with_positive_total_to_pay_is_not_excluded(): void
    {
        $this->assertFalse(TelmexPaymentPolicy::isExcludedFromBankFile(ScholarshipType::TELMEX->value, '500.00'));
    }

    /** @test */
    public function iu_row_with_zero_total_to_pay_is_never_excluded_by_this_policy(): void
    {
        // IU/TELMEX_IU zero-amount rows are NOT Filter B's concern — they
        // still block the whole export via BankDataValidator's NOTHING_TO_PAY
        // (unchanged behavior), never silently excluded.
        $this->assertFalse(TelmexPaymentPolicy::isExcludedFromBankFile(ScholarshipType::IU->value, '0.00'));
    }

    /** @test */
    public function telmex_iu_row_with_zero_total_to_pay_is_never_excluded_by_this_policy(): void
    {
        $this->assertFalse(TelmexPaymentPolicy::isExcludedFromBankFile(ScholarshipType::TELMEX_IU->value, '0.00'));
    }

    /** @test */
    public function is_excluded_from_bank_file_accepts_a_raw_string_type(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isExcludedFromBankFile('TELMEX', '0.00'));
    }

    /** @test */
    public function is_excluded_from_bank_file_accepts_an_enum_object_type(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isExcludedFromBankFile(ScholarshipType::TELMEX, '0.00'));
    }

    /** @test */
    public function is_excluded_from_bank_file_treats_string_and_enum_object_identically(): void
    {
        $stringResult = TelmexPaymentPolicy::isExcludedFromBankFile('TELMEX', '500.00');
        $enumResult   = TelmexPaymentPolicy::isExcludedFromBankFile(ScholarshipType::TELMEX, '500.00');

        $this->assertSame($stringResult, $enumResult);
        $this->assertFalse($stringResult);
    }

    /** @test */
    public function null_type_is_never_excluded_from_the_bank_file(): void
    {
        $this->assertFalse(TelmexPaymentPolicy::isExcludedFromBankFile(null, '0.00'));
    }

    // ── sdd/telmex-cobertura-iu, design Filter A SQL/per-object extension ──
    //
    // A pure TELMEX row with an active coverage FK is a batch candidate even
    // with zero/no temporary increase — the covered Telmex part (frozen on
    // the snapshot by buildSnapshot, PR1) is real money owed by IU. Filter B
    // (isExcludedFromBankFile) is deliberately UNTOUCHED by this change: it
    // keys off total_to_pay, which already reflects the covered amount once
    // calculateFinalAmount (PR1, design D3) has added it to final_amount.

    /** @test */
    public function pure_telmex_with_a_coverage_id_and_zero_increase_is_a_candidate(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, 0, 5));
    }

    /** @test */
    public function pure_telmex_with_a_coverage_id_and_null_increase_is_a_candidate(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, null, 5));
    }

    /** @test */
    public function pure_telmex_without_coverage_or_increase_is_still_not_a_candidate(): void
    {
        // Non-regression: omitting the new 3rd arg (default null) must keep
        // every existing caller's behavior byte-identical.
        $this->assertFalse(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, 0));
        $this->assertFalse(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, null, null));
    }

    /** @test */
    public function coverage_id_as_int_and_as_numeric_string_are_treated_identically(): void
    {
        // D5-style normalizer reuse: PaymentBatchService::rows() reads this
        // column via DB::table() (raw string on some drivers), while
        // PaymentReadinessEvaluator may see it already cast to int by
        // Eloquent — both shapes must flip candidacy the same way.
        $intResult    = TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, 0, 5);
        $stringResult = TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX->value, 0, '5');

        $this->assertSame($intResult, $stringResult);
        $this->assertTrue($stringResult);
    }

    /** @test */
    public function iu_and_telmex_iu_candidacy_are_unaffected_by_a_null_coverage_id(): void
    {
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::IU->value, null, null));
        $this->assertTrue(TelmexPaymentPolicy::isBatchCandidate(ScholarshipType::TELMEX_IU->value, null, null));
    }
}
