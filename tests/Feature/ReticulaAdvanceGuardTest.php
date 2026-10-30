<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S8 (spec R22 / S55-S59): updateReticula must
 * not move the egreso month (reticula_end + 2) onto or before a PENDING
 * advance month of the becario.
 */
class ReticulaAdvanceGuardTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_FILE = 'scholarships/1/reticula/old.pdf';

    private User $admin;
    private ScholarshipProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::disk('public')->put(self::OLD_FILE, 'old');

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $this->profile = ScholarshipProfile::factory()->create([
            'user_id'                => $user->id,
            'reticula_start_date'    => '2024-08-01',
            'reticula_end_date'      => '2028-06-30',
            'reticula_file_path'     => self::OLD_FILE,
            'reticula_original_name' => 'old.pdf',
        ]);
    }

    private function makeRefrend(int $userId, int $year, int $month): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id'                      => $userId,
            'period_year'                  => $year,
            'period_month'                 => $month,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'DRAFT',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Test Becario',
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);
    }

    private function addAdvanceMonths(array $periods, ?int $userId = null): void
    {
        $userId ??= $this->profile->user_id;

        $origin = $this->makeRefrend($userId, 2026, 1);

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $userId,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 1,
            'months_count'        => count($periods),
            'total_amount'        => '1000.00',
            'status'              => 'ACTIVE',
        ]);

        foreach ($periods as [$year, $month, $status]) {
            $refrend = $this->makeRefrend($userId, $year, $month);
            DB::table('scholarship_advance_payment_months')->insert([
                'advance_payment_id' => $header->id,
                'user_id'            => $userId,
                'period_year'        => $year,
                'period_month'       => $month,
                'amount'             => '500.00',
                'refrend_id'         => $refrend->id,
                'status'             => $status,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    private function postReticula(string $endDate, bool $withFile = false)
    {
        $body = [
            'reticula_start_date' => '2024-08-01',
            'reticula_end_date'   => $endDate,
        ];
        if ($withFile) {
            $body['file'] = UploadedFile::fake()->create('nueva.pdf', 100, 'application/pdf');
        }

        return $this->actingAs($this->admin)->post(
            "/api/admin/scholarship-profiles/{$this->profile->user_id}/reticula",
            $body,
            ['Accept' => 'application/json']
        );
    }

    /** @test */
    public function it_rejects_when_the_new_egreso_month_equals_a_pending_advance_month(): void
    {
        $this->addAdvanceMonths([[2026, 5, 'PENDING'], [2026, 6, 'PENDING'], [2026, 7, 'PENDING']]);

        // end 2026-03-31 -> egreso 05/2026
        $response = $this->postReticula('2026-03-31', true);

        $response->assertStatus(422)->assertExactJson([
            'res'  => false,
            'msg'  => 'Con esta retícula el egreso sería en 05/2026, pero el becario tiene 05/2026, 06/2026 y 07/2026 pagados por adelantado. Revierte primero el pago adelantado.',
            'code' => 'RETICULA_ADVANCE_CONFLICT',
            'data' => [
                'egreso_period_year'  => 2026,
                'egreso_period_month' => 5,
                'months'              => [
                    ['period_year' => 2026, 'period_month' => 5],
                    ['period_year' => 2026, 'period_month' => 6],
                    ['period_year' => 2026, 'period_month' => 7],
                ],
            ],
        ]);

        $fresh = $this->profile->fresh();
        $this->assertSame('2028-06-30', $fresh->reticula_end_date->toDateString());
        $this->assertSame(self::OLD_FILE, $fresh->reticula_file_path);
        $this->assertSame('old.pdf', $fresh->reticula_original_name);
        Storage::disk('public')->assertExists(self::OLD_FILE);
        $this->assertSame([self::OLD_FILE], Storage::disk('public')->allFiles());
    }

    /** @test */
    public function the_conflict_message_uses_the_singular_for_a_single_month(): void
    {
        $this->addAdvanceMonths([[2026, 5, 'PENDING']]);

        $this->postReticula('2026-03-31')->assertStatus(422)->assertJsonPath(
            'msg',
            'Con esta retícula el egreso sería en 05/2026, pero el becario tiene 05/2026 pagado por adelantado. Revierte primero el pago adelantado.'
        );
    }

    /** @test */
    public function it_deletes_the_newly_stored_file_when_the_transaction_fails_after_the_store(): void
    {
        ScholarshipProfile::updating(function () {
            throw new \RuntimeException('forced failure after store');
        });

        $this->withoutExceptionHandling();

        try {
            $this->postReticula('2026-05-31', true);
            $this->fail('Expected the forced failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced failure after store', $e->getMessage());
        }

        // Old file untouched, the orphan new file removed, dates unchanged.
        $this->assertSame([self::OLD_FILE], Storage::disk('public')->allFiles());
        $fresh = $this->profile->fresh();
        $this->assertSame(self::OLD_FILE, $fresh->reticula_file_path);
        $this->assertSame('2028-06-30', $fresh->reticula_end_date->toDateString());
    }

    /** @test */
    public function it_rejects_when_a_pending_advance_month_is_after_the_new_egreso(): void
    {
        $this->addAdvanceMonths([[2026, 9, 'PENDING']]);

        // end 2026-03-31 -> egreso 05/2026, advance 09/2026 is after it
        $this->postReticula('2026-03-31')->assertStatus(422)
            ->assertJsonPath('code', 'RETICULA_ADVANCE_CONFLICT')
            ->assertJsonPath('data.months.0.period_month', 9);
    }

    /** @test */
    public function it_accepts_when_the_new_egreso_is_after_every_pending_advance_month(): void
    {
        $this->addAdvanceMonths([[2026, 5, 'PENDING'], [2026, 6, 'PENDING']]);

        // end 2026-05-31 -> egreso 07/2026 (after 06/2026)
        $this->postReticula('2026-05-31', true)->assertOk();

        $fresh = $this->profile->fresh();
        $this->assertSame('2026-05-31', $fresh->reticula_end_date->toDateString());
        Storage::disk('public')->assertMissing(self::OLD_FILE);
        $this->assertNotSame(self::OLD_FILE, $fresh->reticula_file_path);
        Storage::disk('public')->assertExists($fresh->reticula_file_path);
    }

    /** @test */
    public function it_accepts_when_there_is_no_advance(): void
    {
        $this->postReticula('2026-03-31')->assertOk();
        $this->assertSame('2026-03-31', $this->profile->fresh()->reticula_end_date->toDateString());
    }

    /** @test */
    public function it_ignores_non_pending_advance_months(): void
    {
        $this->addAdvanceMonths([
            [2026, 5, 'REACHED'],
            [2026, 6, 'OVERRIDDEN'],
            [2026, 7, 'CANCELLED'],
        ]);

        $this->postReticula('2026-03-31')->assertOk();
        $this->assertSame('2026-03-31', $this->profile->fresh()->reticula_end_date->toDateString());
    }

    /** @test */
    public function it_only_considers_advance_months_of_the_same_user(): void
    {
        $other = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
        $this->addAdvanceMonths([[2026, 6, 'PENDING']], $other->id);

        $this->postReticula('2026-03-31')->assertOk();
    }

    /** @test */
    public function the_generic_profile_update_cannot_change_reticula_dates(): void
    {
        $this->addAdvanceMonths([[2026, 5, 'PENDING']]);

        $this->actingAs($this->admin)->putJson(
            "/api/admin/scholarship-profiles/{$this->profile->user_id}",
            ['reticula_end_date' => '2026-03-31', 'reticula_start_date' => '2020-01-01']
        );

        $fresh = $this->profile->fresh();
        $this->assertSame('2028-06-30', $fresh->reticula_end_date->toDateString());
        $this->assertSame('2024-08-01', $fresh->reticula_start_date->toDateString());
    }
}
