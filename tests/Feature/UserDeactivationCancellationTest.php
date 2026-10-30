<?php

namespace Tests\Feature;

use App\Actions\Scholarship\CloseScholarshipCycleAction;
use App\Enums\ScholarshipType;
use App\Enums\WithholdingCancellationReason;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Models\ScholarshipWithholding;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/retenciones-pendientes, PR5 (R6, S7-S9): deactivating a user through
 * the three admin controllers cancels the pending retentions and the advance
 * payments of a becario, in one transaction with the user update.
 */
class UserDeactivationCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 5, 10, 12));
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** resource, response key */
    public function controllers(): array
    {
        return [
            'users'     => ['users', 'updateUser'],
            'graduates' => ['graduates', 'updateGraduate'],
            'business'  => ['business', 'updateBusiness'],
        ];
    }

    private function becario(bool $active = true): User
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => $active, 'first_name' => 'Original']);
        ScholarshipProfile::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    private function refrend(User $user, int $month, array $extra = []): ScholarshipRefrend
    {
        return ScholarshipRefrend::create(array_merge([
            'user_id' => $user->id, 'period_year' => 2026, 'period_month' => $month,
            'status' => 'DRAFT', 'workflow_status' => 'DRAFT', 'base_amount' => 1000,
            'discount_percentage' => 0, 'discount_amount' => 0, 'final_amount' => 1000,
            'amount_pending_from_previous' => 0, 'snapshot_name' => 'T',
            'snapshot_scholarship_type' => ScholarshipType::IU->value,
        ], $extra));
    }

    private function withholding(User $user, int $month): ScholarshipWithholding
    {
        $origin = $this->refrend($user, $month, ['status' => 'WITHHELD']);

        return ScholarshipWithholding::create([
            'user_id' => $user->id, 'origin_refrend_id' => $origin->id, 'period_year' => 2026,
            'period_month' => $month, 'withheld_amount' => '100.00', 'paid_amount' => '0.00', 'status' => 'PENDING',
        ]);
    }

    /**
     * Advance paid from March covering the given months.
     *
     * @param array<int, array<string, mixed>> $refrendExtra per-month refrend overrides
     * @return array{header: ScholarshipAdvancePayment, refrends: array<int, ScholarshipRefrend>, months: array<int, ScholarshipAdvancePaymentMonth>}
     */
    private function advance(User $user, array $months, array $refrendExtra = []): array
    {
        $origin = $this->refrend($user, 3, ['advance_payment_amount' => number_format(1000 * count($months), 2, '.', '')]);
        $header = ScholarshipAdvancePayment::create([
            'user_id' => $user->id, 'origin_refrend_id' => $origin->id, 'origin_period_year' => 2026,
            'origin_period_month' => 3, 'months_count' => count($months),
            'total_amount' => number_format(1000 * count($months), 2, '.', ''),
            'status' => ScholarshipAdvancePayment::STATUS_ACTIVE,
        ]);
        $refrends = $rows = [];
        foreach ($months as $m) {
            $refrends[$m] = $this->refrend($user, $m, array_merge(['created_via' => 'ADVANCE_PAYMENT'], $refrendExtra[$m] ?? []));
            $rows[$m]     = ScholarshipAdvancePaymentMonth::create([
                'advance_payment_id' => $header->id, 'user_id' => $user->id, 'period_year' => 2026,
                'period_month' => $m, 'amount' => '1000.00', 'refrend_id' => $refrends[$m]->id,
                'status' => ScholarshipAdvancePaymentMonth::STATUS_PENDING,
            ]);
        }

        return ['header' => $header, 'refrends' => $refrends, 'months' => $rows];
    }

    private function updateAs(string $resource, User $user, array $payload)
    {
        return $this->actingAs($this->admin)->putJson("/api/admin/{$resource}/{$user->id}", $payload);
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function deactivating_a_becario_cancels_withholdings_and_advances_and_keeps_other_fields(string $resource, string $key): void
    {
        $user = $this->becario();
        $w1   = $this->withholding($user, 1);
        $w2   = $this->withholding($user, 2);
        $adv  = $this->advance($user, [5, 6, 7]);

        $response = $this->updateAs($resource, $user, ['active' => false, 'first_name' => 'Changed']);

        $response->assertOk()
            ->assertJsonPath('res', true)
            ->assertJsonPath('msg', 'Usuario actualizado correctamente')
            ->assertJsonPath("{$key}.first_name", 'Changed')
            ->assertJsonPath('deactivation_effects.withholdings_cancelled', 2)
            ->assertJsonPath('deactivation_effects.advance_months_cancelled', 3);

        $fresh = $user->fresh();
        $this->assertFalse((bool) $fresh->active);
        $this->assertSame('Changed', $fresh->first_name);

        foreach ([$w1, $w2] as $w) {
            $this->assertSame('CANCELLED', $w->fresh()->status);
            $this->assertSame(WithholdingCancellationReason::USER_DEACTIVATED, $w->fresh()->cancellation_reason);
            $this->assertSame($this->admin->id, (int) $w->fresh()->cancelled_by_id);
        }

        $header = $adv['header']->fresh();
        $this->assertSame('USER_DEACTIVATED', $header->cancel_reason);
        $this->assertSame($this->admin->id, (int) $header->cancelled_by_id);
        foreach ([5, 6, 7] as $m) {
            $r = $adv['refrends'][$m]->fresh();
            $this->assertSame('CANCELLED', $r->status->value);
            $this->assertSame('CLOSED', $r->workflow_status);
            $this->assertSame('0.00', $r->final_amount);
            $this->assertSame('Cancelado por DESACTIVACIÓN DE USUARIO del 05/2026', $r->resolution_notes);
        }
        $this->assertSame(1, ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->count());
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function a_transition_without_cancellable_data_reports_zero_effects(string $resource): void
    {
        $user = $this->becario();

        $this->updateAs($resource, $user, ['active' => false])
            ->assertOk()
            ->assertJsonPath('deactivation_effects.withholdings_cancelled', 0)
            ->assertJsonPath('deactivation_effects.advance_months_cancelled', 0)
            ->assertJsonPath('deactivation_effects.overpaid_amount', '0.00');
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function false_to_false_and_unchanged_active_are_no_ops(string $resource): void
    {
        $inactive = $this->becario(false);
        $w        = $this->withholding($inactive, 2);
        $active   = $this->becario();
        $w2       = $this->withholding($active, 2);

        $this->updateAs($resource, $inactive, ['active' => false])
            ->assertOk()->assertJsonPath('deactivation_effects', null);
        $this->updateAs($resource, $active, ['first_name' => 'Still active'])
            ->assertOk()->assertJsonPath('deactivation_effects', null);
        $this->updateAs($resource, $active, ['active' => true])
            ->assertOk()->assertJsonPath('deactivation_effects', null);

        $this->assertSame('PENDING', $w->fresh()->status);
        $this->assertSame('PENDING', $w2->fresh()->status);
        $this->assertTrue((bool) $active->fresh()->active);
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function reactivating_does_not_restore_anything(string $resource): void
    {
        $user = $this->becario();
        $w    = $this->withholding($user, 2);
        $adv  = $this->advance($user, [5, 6]);

        $this->updateAs($resource, $user, ['active' => false])->assertOk();
        $this->updateAs($resource, $user, ['active' => true])
            ->assertOk()->assertJsonPath('deactivation_effects', null);

        $this->assertTrue((bool) $user->fresh()->active);
        $this->assertSame('CANCELLED', $w->fresh()->status);
        $this->assertSame('CANCELLED', $adv['header']->fresh()->status);
        $this->assertSame('CANCELLED', $adv['refrends'][6]->fresh()->status->value);
        $this->assertSame(1, ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->count());
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function deactivating_a_non_becario_is_unchanged(string $resource, string $key): void
    {
        $user = User::factory()->create(['user_type' => 'BUSINESS', 'active' => true]);
        // Stray data for a user without a ScholarshipProfile must not be touched.
        $w = $this->withholding($user, 2);

        $this->updateAs($resource, $user, ['active' => false])
            ->assertOk()
            ->assertJsonPath('res', true)
            ->assertJsonPath('deactivation_effects', null);

        $this->assertFalse((bool) $user->fresh()->active);
        $this->assertSame('PENDING', $w->fresh()->status);
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function an_anchor_month_refrend_already_locked_is_left_as_consumed_history(string $resource): void
    {
        $user = $this->becario();
        $adv  = $this->advance($user, [5, 6], [5 => ['workflow_status' => 'CLOSED', 'locked_at' => now()->subDay()]]);

        $this->updateAs($resource, $user, ['active' => false])
            ->assertOk()
            ->assertJsonPath('deactivation_effects.advance_months_cancelled', 1);

        $this->assertFalse((bool) $user->fresh()->active);
        $this->assertSame(ScholarshipAdvancePaymentMonth::STATUS_PENDING, $adv['months'][5]->fresh()->status);
        $this->assertNotSame('CANCELLED', $adv['refrends'][5]->fresh()->status->value);
        $this->assertNull($adv['refrends'][5]->fresh()->resolution_notes);
        $this->assertSame('CANCELLED', $adv['refrends'][6]->fresh()->status->value);
    }

    /**
     * @test
     * @dataProvider controllers
     */
    public function a_later_locked_month_blocks_with_409_and_changes_nothing(string $resource): void
    {
        $user = $this->becario();
        $w    = $this->withholding($user, 2);
        $adv  = $this->advance($user, [5, 6, 7], [7 => ['workflow_status' => 'CLOSED', 'locked_at' => now()->subDay()]]);

        $response = $this->updateAs($resource, $user, ['active' => false, 'first_name' => 'Changed']);

        $response->assertStatus(409)
            ->assertJsonPath('res', false)
            ->assertJsonPath('code', 'CYCLE_CUT_BLOCKED')
            ->assertJsonStructure(['res', 'msg', 'code', 'data' => ['blocking_months']])
            ->assertJsonPath('data.blocking_months.0.month', 7);

        $fresh = $user->fresh();
        $this->assertTrue((bool) $fresh->active);
        $this->assertSame('Original', $fresh->first_name);
        $this->assertSame('PENDING', $w->fresh()->status);
        $this->assertNull($w->fresh()->cancellation_reason);
        $this->assertSame(ScholarshipAdvancePayment::STATUS_ACTIVE, $adv['header']->fresh()->status);
        $this->assertSame('DRAFT', $adv['refrends'][5]->fresh()->workflow_status);
        $this->assertSame(0, ScholarshipRefrendLog::where('action', 'ADVANCE_PAYMENT_CANCELLED_BY_CYCLE_CUT')->count());
    }

    /** @test */
    public function the_manual_cut_deactivation_is_not_processed_a_second_time(): void
    {
        $user = $this->becario();
        $cut  = $this->refrend($user, 5, ['status' => 'CANCELLED', 'workflow_status' => 'CLOSED']);
        $w    = $this->withholding($user, 2);

        $this->app->make(CloseScholarshipCycleAction::class)->execute($cut, 'BAJA_DEFINITIVA', $this->admin->id);

        $this->assertFalse((bool) $user->fresh()->active);
        $this->assertSame(WithholdingCancellationReason::CYCLE_CUT, $w->fresh()->cancellation_reason);

        $this->updateAs('users', $user, ['active' => false])
            ->assertOk()->assertJsonPath('deactivation_effects', null);

        $this->assertSame(WithholdingCancellationReason::CYCLE_CUT, $w->fresh()->cancellation_reason);
        $this->assertSame(1, ScholarshipRefrendLog::where('action', 'WITHHOLDING_CANCELLED')->where('scholarship_refrend_id', $w->origin_refrend_id)->count());
    }
}
