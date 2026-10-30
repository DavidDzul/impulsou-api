<?php

namespace Tests\Feature;

use App\Actions\Scholarship\RecordAdvancePaymentAction;
use App\Actions\Scholarship\RecordPaymentSituationAction;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\GenerateMonthlyRefrendsService;
use App\Services\Users\UserDeactivationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RecordsLockOrder;
use Tests\TestCase;

/**
 * sdd/retenciones-pendientes (verify WARNING 1): global lock order. Every path
 * that locks or updates a becario's `users` row takes that lock FIRST in its
 * transaction, before any refrend / profile / withholding / advance lock, so
 * two such paths can never wait on each other in opposite order (InnoDB 1213).
 * The tests assert the order of statements; see BecarioLockOrder for the rule.
 */
class BecarioLockOrderTest extends TestCase
{
    use RefreshDatabase;
    use RecordsLockOrder;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 15));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function becario(array $profile = []): ScholarshipProfile
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

        return ScholarshipProfile::create(array_merge([
            'user_id'             => $user->id,
            'scholarship_type'    => ScholarshipType::IU->value,
            'monthly_amount'      => 2000.00,
            'monto_apoyo'         => 0,
            'reticula_start_date' => '2000-01-01',
            'reticula_end_date'   => '2099-12-31',
            'payment_start_date'  => '2026-01-01',
            'config_set_at'       => now(),
        ], $profile));
    }

    private function refrend(int $userId, int $month): ScholarshipRefrend
    {
        return ScholarshipRefrend::create([
            'user_id' => $userId, 'period_year' => 2026, 'period_month' => $month,
            'refrend_type' => RefrendType::NORMAL->value, 'status' => 'DRAFT', 'workflow_status' => 'DRAFT',
            'base_amount' => 1000, 'discount_percentage' => 0, 'discount_amount' => 0, 'final_amount' => 1000,
            'amount_pending_from_previous' => 0, 'snapshot_name' => 'T',
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ]);
    }

    /** @test */
    public function recording_an_advance_locks_the_user_row_before_refrend_and_profile(): void
    {
        $admin  = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $p      = $this->becario();
        $origin = $this->refrend($p->user_id, 9);

        $this->startRecordingLockOrder();
        app(RecordAdvancePaymentAction::class)->execute($origin, ['months' => [['year' => 2026, 'month' => 12]]], $admin->id);

        $this->assertUsersRowLockedFirst();
    }

    /** @test */
    public function a_terminal_cycle_cut_locks_the_user_row_before_any_refrend_write(): void
    {
        $admin   = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $p       = $this->becario();
        $refrend = $this->refrend($p->user_id, 9);

        $this->startRecordingLockOrder();
        app(RecordPaymentSituationAction::class)->execute($refrend, [
            'resolution_type'  => 'BAJA_DEFINITIVA',
            'resolution_cause' => 'BAJO_PROMEDIO',
        ], $admin->id);

        $this->assertUsersRowLockedFirst();
    }

    /** @test */
    public function the_automatic_egreso_locks_the_user_row_before_creating_the_egreso_refrend(): void
    {
        $p = $this->becario(['reticula_end_date' => '2026-07-31']);

        $this->startRecordingLockOrder();
        app(GenerateMonthlyRefrendsService::class)->generateForUser($p, 2026, 9);

        $this->assertUsersRowLockedFirst();
    }

    /** @test */
    public function deactivation_keeps_the_user_row_lock_first(): void
    {
        $p = $this->becario();
        $this->refrend($p->user_id, 9);
        $user = User::findOrFail($p->user_id);

        $this->startRecordingLockOrder();
        app(UserDeactivationService::class)->save($user, ['active' => false], $user->id);

        $this->assertUsersRowLockedFirst();
    }
}
