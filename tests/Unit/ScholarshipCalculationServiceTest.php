<?php

namespace Tests\Unit;

use App\Enums\DiscountType;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Services\ScholarshipCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScholarshipCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScholarshipCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(ScholarshipCalculationService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeProfile(array $overrides = []): ScholarshipProfile
    {
        $user = \App\Models\User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipProfile::create(array_merge([
            'user_id'                    => $user->id,
            'scholarship_type'           => ScholarshipType::IU->value,
            'monthly_amount'             => 2500.00,
            'active_discount_percentage' => 15.00,
            'discount_reason'            => null,
            'discount_valid_until'       => Carbon::tomorrow()->toDateString(),
            // payment_start_date column still exists on SQLite (dropColumn skipped)
            'payment_start_date'         => now()->toDateString(),
        ], $overrides));
    }

    private function makeRefrend(int $userId): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => 6,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'base_amount'                  => 2500.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 2500.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_generation'          => null,
            'snapshot_generation_id'       => null,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);
    }

    // ── S-DESC-01: discount_reason non-null ───────────────────────────────────

    /** @test */
    public function discount_description_includes_reason_when_profile_has_discount_reason(): void
    {
        $profile = $this->makeProfile([
            'discount_reason'      => 'Promedio semestral bajo',
            'discount_valid_until' => Carbon::tomorrow()->toDateString(),
        ]);
        $refrend = $this->makeRefrend($profile->user_id);

        $discount = $this->service->applyAcademicDiscount($refrend, $profile);

        $this->assertNotNull($discount);
        $this->assertStringContainsString('Promedio semestral bajo', $discount->description);
        $this->assertStringContainsString('Descuento por promedio académico vigente.', $discount->description);
        $this->assertSame(DiscountType::PROMEDIO_BAJO->value, $discount->discount_type);
    }

    // ── S-DESC-02: discount_reason null ──────────────────────────────────────

    /** @test */
    public function discount_description_uses_fallback_when_discount_reason_is_null(): void
    {
        $profile = $this->makeProfile([
            'discount_reason'      => null,
            'discount_valid_until' => Carbon::tomorrow()->toDateString(),
        ]);
        $refrend = $this->makeRefrend($profile->user_id);

        $discount = $this->service->applyAcademicDiscount($refrend, $profile);

        $this->assertNotNull($discount);
        $this->assertSame('Descuento por promedio académico vigente.', $discount->description);
    }

    // ── S-DESC-03: expired discount_valid_until ───────────────────────────────

    /** @test */
    public function no_discount_created_when_discount_valid_until_is_expired(): void
    {
        $profile = $this->makeProfile([
            'discount_reason'      => 'Baja calificación',
            'discount_valid_until' => Carbon::yesterday()->toDateString(),
        ]);
        $refrend = $this->makeRefrend($profile->user_id);

        $discount = $this->service->applyAcademicDiscount($refrend, $profile);

        $this->assertNull($discount);
    }
}
