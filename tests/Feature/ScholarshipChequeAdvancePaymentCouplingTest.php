<?php

namespace Tests\Feature;

use App\Actions\Scholarship\MarkChequePaidAction;
use App\Actions\Scholarship\RecordAdvancePaymentAction;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\AdvancePaymentReconciler;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/pago-cheque task 3.3: a cheque-paid ORIGIN refrend (payment_batch_id
 * NULL) that has an advance payment registered must keep the advance
 * ledger, the payment document context and the generated future refrends
 * intact.
 */
class ScholarshipChequeAdvancePaymentCouplingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private ScholarshipRefrend $origin;
    private ScholarshipAdvancePayment $advance;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 9, 15));
        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');

        $user = User::factory()->create([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => 'MERIDA',
            'active'     => true,
            'enrollment' => 'MAT-123456',
        ]);
        ScholarshipPaymentData::factory()->cheque()->create(['user_id' => $user->id]);
        ScholarshipProfile::create([
            'user_id'                  => $user->id,
            'scholarship_type'         => ScholarshipType::IU->value,
            'monthly_amount'           => 2000.00,
            'monto_apoyo'              => 0,
            'reticula_start_date'      => '2000-01-01',
            'reticula_end_date'        => '2099-12-31',
            'payment_start_date'       => now()->toDateString(),
            'config_set_at'            => now(),
            'advance_payment_eligible' => true,
        ]);

        $this->origin = ScholarshipRefrend::create([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 9,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Becario Adelanto',
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => 'MERIDA',
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ]);

        $this->actingAs($this->admin);
        $this->advance = app(RecordAdvancePaymentAction::class)->execute($this->origin, [
            'months' => [['year' => 2026, 'month' => 10], ['year' => 2026, 'month' => 11]],
        ], $this->admin->id);
        $this->origin->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function markOrigin(): void
    {
        app(MarkChequePaidAction::class)->execute($this->origin->id, $this->admin->id, 'ADV-1', null, null);
        $this->origin->refresh();
    }

    /** @test */
    public function an_origin_refrend_with_an_advance_can_be_marked_paid_by_cheque(): void
    {
        $this->assertGreaterThan(0, (float) $this->origin->advance_payment_amount);

        $this->markOrigin();

        $this->assertSame(RefrendStatus::PAID, $this->origin->status);
        $this->assertSame('CLOSED', $this->origin->workflow_status);
        $this->assertNull($this->origin->payment_batch_id);
        $this->assertSame('ADV-1', $this->origin->cheque_number);
    }

    /** @test */
    public function the_advance_ledger_and_document_context_stay_correct_after_the_cheque(): void
    {
        $this->markOrigin();

        $this->assertSame($this->advance->total_amount, $this->origin->advance_payment_amount);
        $this->assertSame('ACTIVE', $this->advance->fresh()->status);
        $this->assertSame(2, $this->advance->months()->where('status', 'PENDING')->count());

        $context = $this->getJson("/api/admin/scholarship-payments/{$this->origin->id}/document")
            ->assertOk()
            ->json('data.advance_payment');

        $this->assertTrue($context['has_registered_batch']);
        $this->assertSame(2, $context['registered_months_count']);
        $this->assertEquals($this->advance->total_amount, $context['registered_total_amount']);
        $this->assertFalse($context['settled_as_advance']);
    }

    /** @test */
    public function future_refrends_remain_unpaid_and_reconcile_normally(): void
    {
        $this->markOrigin();

        $futures = ScholarshipRefrend::where('user_id', $this->origin->user_id)
            ->whereIn('period_month', [10, 11])->get();
        $this->assertCount(2, $futures);
        foreach ($futures as $future) {
            $this->assertNotSame(RefrendStatus::PAID, $future->status);
            $this->assertNull($future->payment_batch_id);
            $this->assertNull($future->locked_at);
        }

        $child = $futures->first();
        $child->update(['final_amount' => 0]);
        app(AdvancePaymentReconciler::class)->reconcile($child->fresh(), 'SIN_PAGO', null);

        $month = ScholarshipAdvancePaymentMonth::where('refrend_id', $child->id)->first();
        $this->assertSame('REACHED', $month->status);
    }

    /** @test */
    public function the_cheque_section_shows_the_paid_origin_with_its_advance_amount(): void
    {
        $this->markOrigin();

        $response = $this->getJson('/api/admin/scholarship-payments?' . http_build_query([
            'campus' => 'MERIDA', 'period_year' => 2026, 'period_month' => 9, 'batch_type' => 'CERT_UNID',
        ]))->assertOk();

        $row = $response->json('data.cheque_rows.0');
        $this->assertSame('PAID', $row['status']);
        $this->assertSame('CHEQUE', $row['payment_method']);
        $this->assertSame(number_format((float) $this->origin->advance_payment_amount, 2, '.', ''), $row['advance_payment_amount']);
        $this->assertSame(1, $response->json('data.cheque_summary.paid'));
    }

    /** @test */
    public function an_advance_cannot_be_registered_after_the_origin_is_cheque_paid(): void
    {
        $this->markOrigin();

        $this->expectException(\DomainException::class);
        app(RecordAdvancePaymentAction::class)->execute($this->origin, [
            'months' => [['year' => 2026, 'month' => 12]],
        ], $this->admin->id);
    }
}
