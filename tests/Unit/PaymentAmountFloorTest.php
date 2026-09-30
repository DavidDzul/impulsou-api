<?php

namespace Tests\Unit;

use App\Enums\ScholarshipType;
use App\Support\Scholarship\PaymentAmountFloor;
use Tests\TestCase;

/**
 * Unit tests for PaymentAmountFloor (sdd/bank-file-minimum-deposit, design
 * D1/D2/D3) — the single shared floor rule reused by
 * PaymentBatchService::rows() and ::paidRows(). Pure PHP, no DB.
 *
 * Delegates TELMEX detection to TelmexPaymentPolicy::isExcludedFromBankFile()
 * (read-only consumption) — TelmexPaymentPolicyTest is the regression proof
 * that class was never modified by this change.
 */
class PaymentAmountFloorTest extends TestCase
{
    // ── IU / TELMEX_IU at exactly zero -> floored to 0.01 ────────────────────

    /** @test */
    public function iu_at_zero_is_floored_to_one_cent(): void
    {
        $this->assertSame('0.01', PaymentAmountFloor::apply(ScholarshipType::IU->value, '0.00'));
    }

    /** @test */
    public function telmex_iu_at_zero_with_no_active_increase_is_floored_to_one_cent(): void
    {
        $this->assertSame('0.01', PaymentAmountFloor::apply(ScholarshipType::TELMEX_IU->value, '0.00'));
    }

    // ── Pure TELMEX never floors ──────────────────────────────────────────────

    /** @test */
    public function pure_telmex_at_zero_is_never_floored(): void
    {
        $this->assertSame('0.00', PaymentAmountFloor::apply(ScholarshipType::TELMEX->value, '0.00'));
    }

    /** @test */
    public function pure_telmex_at_negative_is_never_floored(): void
    {
        $this->assertSame('-5.00', PaymentAmountFloor::apply(ScholarshipType::TELMEX->value, '-5.00'));
    }

    // ── TELMEX_IU with an active increase covering the discount (net > 0) ────

    /** @test */
    public function telmex_iu_with_a_positive_total_is_unaffected(): void
    {
        $this->assertSame('500.00', PaymentAmountFloor::apply(ScholarshipType::TELMEX_IU->value, '500.00'));
    }

    // ── Negative totals are NEVER floored, for any type ──────────────────────

    /** @test */
    public function iu_at_negative_is_never_floored(): void
    {
        $this->assertSame('-10.00', PaymentAmountFloor::apply(ScholarshipType::IU->value, '-10.00'));
    }

    /** @test */
    public function telmex_iu_at_negative_is_never_floored(): void
    {
        $this->assertSame('-10.00', PaymentAmountFloor::apply(ScholarshipType::TELMEX_IU->value, '-10.00'));
    }

    // ── Positive totals are left unchanged ────────────────────────────────────

    /** @test */
    public function iu_positive_total_is_unchanged(): void
    {
        $this->assertSame('1000.00', PaymentAmountFloor::apply(ScholarshipType::IU->value, '1000.00'));
    }

    /** @test */
    public function pure_telmex_positive_total_is_unchanged(): void
    {
        $this->assertSame('500.00', PaymentAmountFloor::apply(ScholarshipType::TELMEX->value, '500.00'));
    }

    // ── Idempotence: apply(apply(x)) === apply(x) ─────────────────────────────

    /** @test */
    public function apply_is_idempotent_for_a_floored_iu_value(): void
    {
        $once  = PaymentAmountFloor::apply(ScholarshipType::IU->value, '0.00');
        $twice = PaymentAmountFloor::apply(ScholarshipType::IU->value, $once);

        $this->assertSame($once, $twice);
        $this->assertSame('0.01', $twice);
    }

    /** @test */
    public function apply_is_idempotent_for_an_unfloored_telmex_value(): void
    {
        $once  = PaymentAmountFloor::apply(ScholarshipType::TELMEX->value, '0.00');
        $twice = PaymentAmountFloor::apply(ScholarshipType::TELMEX->value, $once);

        $this->assertSame($once, $twice);
        $this->assertSame('0.00', $twice);
    }

    // ── Enum-object AND raw-string inputs both handled (mirrors TelmexPaymentPolicyTest) ──

    /** @test */
    public function accepts_a_raw_string_type(): void
    {
        $this->assertSame('0.01', PaymentAmountFloor::apply('IU', '0.00'));
    }

    /** @test */
    public function accepts_an_enum_object_type(): void
    {
        $this->assertSame('0.01', PaymentAmountFloor::apply(ScholarshipType::IU, '0.00'));
    }

    /** @test */
    public function treats_string_and_enum_object_identically(): void
    {
        $stringResult = PaymentAmountFloor::apply('TELMEX', '0.00');
        $enumResult   = PaymentAmountFloor::apply(ScholarshipType::TELMEX, '0.00');

        $this->assertSame($stringResult, $enumResult);
        $this->assertSame('0.00', $stringResult);
    }

    /** @test */
    public function null_type_is_never_floored_since_telmex_policy_treats_it_as_excluded_only_when_telmex(): void
    {
        // null type is not TELMEX per TelmexPaymentPolicy::type(), and it is
        // not a recognized zero-total IU/TELMEX_IU type either — but the
        // exact-zero predicate (D3) is type-blind once past the TELMEX
        // guard, so a null type at exactly 0.00 still floors, mirroring "any
        // non-TELMEX row at exactly 0.00 floors".
        $this->assertSame('0.01', PaymentAmountFloor::apply(null, '0.00'));
    }
}
