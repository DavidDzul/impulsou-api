<?php

namespace Tests\Unit;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScholarshipProfileTemporaryIncreaseTest extends TestCase
{
    use RefreshDatabase;

    private function makeProfile(array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE',
            'campus'    => 'MERIDA',
            'active'    => true,
        ]);

        return ScholarshipProfile::create(array_merge([
            'user_id'                    => $user->id,
            'scholarship_type'           => 'IU',
            'monthly_amount'             => 2500.00,
            'payment_start_date'         => now()->toDateString(),
        ], $overrides));
    }

    // ── Fillable / casts / persistence ──────────────────────────────────────

    /** @test */
    public function temporary_increase_columns_are_fillable_and_persist(): void
    {
        $grantedBy = User::factory()->create();

        $profile = $this->makeProfile([
            'temporary_increase_amount'         => 500.00,
            'temporary_increase_valid_from'     => '2026-09-01',
            'temporary_increase_valid_until'    => '2026-09-30',
            'temporary_increase_reason'         => 'Apoyo transporte',
            'temporary_increase_granted_by_id'  => $grantedBy->id,
        ]);

        $profile->refresh();

        $this->assertSame('500.00', (string) $profile->temporary_increase_amount);
        $this->assertInstanceOf(Carbon::class, $profile->temporary_increase_valid_from);
        $this->assertSame('2026-09-01', $profile->temporary_increase_valid_from->toDateString());
        $this->assertInstanceOf(Carbon::class, $profile->temporary_increase_valid_until);
        $this->assertSame('2026-09-30', $profile->temporary_increase_valid_until->toDateString());
        $this->assertSame('Apoyo transporte', $profile->temporary_increase_reason);
        $this->assertSame($grantedBy->id, $profile->temporary_increase_granted_by_id);
    }

    /** @test */
    public function granted_by_relation_resolves_the_user(): void
    {
        $grantedBy = User::factory()->create();

        $profile = $this->makeProfile([
            'temporary_increase_amount'        => 300.00,
            'temporary_increase_valid_from'    => '2026-09-01',
            'temporary_increase_valid_until'   => '2026-09-30',
            'temporary_increase_reason'        => 'Motivo',
            'temporary_increase_granted_by_id' => $grantedBy->id,
        ]);

        $this->assertTrue($profile->grantedBy()->exists());
        $this->assertSame($grantedBy->id, $profile->grantedBy->id);
    }

    /** @test */
    public function discount_valid_from_is_fillable_and_cast_to_date(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage' => 10.00,
            'discount_valid_from'        => '2026-09-01',
            'discount_valid_until'       => '2026-09-30',
        ]);

        $profile->refresh();

        $this->assertInstanceOf(Carbon::class, $profile->discount_valid_from);
        $this->assertSame('2026-09-01', $profile->discount_valid_from->toDateString());
    }

    // ── isTemporaryIncreaseActiveOn() — inclusive range boundaries ──────────

    /** @test */
    public function temporary_increase_is_active_when_on_equals_valid_from(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => '2026-09-01',
            'temporary_increase_valid_until' => '2026-09-30',
        ]);

        $this->assertTrue($profile->isTemporaryIncreaseActiveOn(Carbon::parse('2026-09-01')));
    }

    /** @test */
    public function temporary_increase_is_active_when_on_equals_valid_until(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => '2026-09-01',
            'temporary_increase_valid_until' => '2026-09-30',
        ]);

        $this->assertTrue($profile->isTemporaryIncreaseActiveOn(Carbon::parse('2026-09-30')));
    }

    /** @test */
    public function temporary_increase_is_inactive_one_day_before_valid_from(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => '2026-09-01',
            'temporary_increase_valid_until' => '2026-09-30',
        ]);

        $this->assertFalse($profile->isTemporaryIncreaseActiveOn(Carbon::parse('2026-08-31')));
    }

    /** @test */
    public function temporary_increase_is_inactive_one_day_after_valid_until(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => '2026-09-01',
            'temporary_increase_valid_until' => '2026-09-30',
        ]);

        $this->assertFalse($profile->isTemporaryIncreaseActiveOn(Carbon::parse('2026-10-01')));
    }

    /** @test */
    public function temporary_increase_is_inactive_when_valid_from_is_null(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => null,
            'temporary_increase_valid_until' => '2026-09-30',
        ]);

        $this->assertFalse($profile->isTemporaryIncreaseActiveOn(Carbon::parse('2026-09-15')));
    }

    /** @test */
    public function temporary_increase_is_inactive_when_valid_until_is_null(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => '2026-09-01',
            'temporary_increase_valid_until' => null,
        ]);

        $this->assertFalse($profile->isTemporaryIncreaseActiveOn(Carbon::parse('2026-09-15')));
    }

    /** @test */
    public function temporary_increase_is_inactive_when_amount_is_zero(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 0,
            'temporary_increase_valid_from'  => '2026-09-01',
            'temporary_increase_valid_until' => '2026-09-30',
        ]);

        $this->assertFalse($profile->isTemporaryIncreaseActiveOn(Carbon::parse('2026-09-15')));
    }

    /** @test */
    public function temporary_increase_is_inactive_when_amount_is_null(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => null,
            'temporary_increase_valid_from'  => '2026-09-01',
            'temporary_increase_valid_until' => '2026-09-30',
        ]);

        $this->assertFalse($profile->isTemporaryIncreaseActiveOn(Carbon::parse('2026-09-15')));
    }

    /** @test */
    public function temporary_increase_defaults_to_today_when_on_is_omitted(): void
    {
        $profile = $this->makeProfile([
            'temporary_increase_amount'      => 500.00,
            'temporary_increase_valid_from'  => Carbon::today()->toDateString(),
            'temporary_increase_valid_until' => Carbon::today()->toDateString(),
        ]);

        $this->assertTrue($profile->isTemporaryIncreaseActiveOn());
    }

    // ── isDiscountActiveOn() — same inclusive range primitive ───────────────

    /** @test */
    public function discount_is_active_when_on_equals_valid_from(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage' => 10.00,
            'discount_valid_from'        => '2026-09-01',
            'discount_valid_until'       => '2026-09-30',
        ]);

        $this->assertTrue($profile->isDiscountActiveOn(Carbon::parse('2026-09-01')));
    }

    /** @test */
    public function discount_is_active_when_on_equals_valid_until(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage' => 10.00,
            'discount_valid_from'        => '2026-09-01',
            'discount_valid_until'       => '2026-09-30',
        ]);

        $this->assertTrue($profile->isDiscountActiveOn(Carbon::parse('2026-09-30')));
    }

    /** @test */
    public function discount_is_inactive_one_day_after_valid_until(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage' => 10.00,
            'discount_valid_from'        => '2026-09-01',
            'discount_valid_until'       => '2026-09-30',
        ]);

        $this->assertFalse($profile->isDiscountActiveOn(Carbon::parse('2026-10-01')));
    }

    /** @test */
    public function discount_is_inactive_when_valid_from_is_null(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage' => 10.00,
            'discount_valid_from'        => null,
            'discount_valid_until'       => '2026-09-30',
        ]);

        $this->assertFalse($profile->isDiscountActiveOn(Carbon::parse('2026-09-15')));
    }

    /** @test */
    public function discount_is_inactive_when_valid_until_is_null(): void
    {
        $profile = $this->makeProfile([
            'active_discount_percentage' => 10.00,
            'discount_valid_from'        => '2026-09-01',
            'discount_valid_until'       => null,
        ]);

        $this->assertFalse($profile->isDiscountActiveOn(Carbon::parse('2026-09-15')));
    }
}
