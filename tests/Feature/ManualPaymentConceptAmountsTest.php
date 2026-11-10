<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\ManualPaymentConceptAmounts;
use App\Services\Scholarship\PaymentBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/bank-file-split-other-banks-cheques PR1 (R3, R6, S2): the frozen
 * per-concept amounts of a manual payment must match what rows() displays.
 */
class ManualPaymentConceptAmountsTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';

    private function refrend(array $overrides = []): ScholarshipRefrend
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => self::CAMPUS, 'active' => true]);

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => 2026,
            'period_month'                 => 5,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => RefrendStatus::DRAFT->value,
            'workflow_status'              => 'CLOSED',
            'base_amount'                  => 2000.00,
            'snapshot_monto_apoyo'         => 200.00,
            'snapshot_gross_amount'        => 2200.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 500.00,
            'final_amount'                 => 1700.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => 'Becario ' . $user->id,
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    private function service(): ManualPaymentConceptAmounts
    {
        return $this->app->make(ManualPaymentConceptAmounts::class);
    }

    private function cents(string $money): int
    {
        return (int) round((float) $money * 100);
    }

    private function rowsSplit(int $refrendId): array
    {
        $rows = $this->app->make(PaymentBatchService::class)->rows(self::CAMPUS, 2026, 5);
        foreach ($rows as $row) {
            if ($row['refrend_id'] === $refrendId) {
                return $row;
            }
        }
        $this->fail('refrend not in rows()');
    }

    /** @test */
    public function it_matches_the_rows_concept_split(): void
    {
        $refrend = $this->refrend();

        $amounts = $this->service()->forRefrend($refrend->id);
        $row     = $this->rowsSplit($refrend->id);

        $this->assertSame($row['concept_split']['beca'], $amounts['beca']);
        $this->assertSame($row['concept_split']['apoyo'], $amounts['apoyo']);
    }

    /** @test */
    public function it_matches_rows_with_carryover_and_sums_to_total_to_pay_in_cents(): void
    {
        $refrend = $this->refrend(['amount_pending_from_previous' => 300.00, 'advance_payment_amount' => 100.00]);

        $amounts = $this->service()->forRefrend($refrend->id);
        $row     = $this->rowsSplit($refrend->id);

        $this->assertSame($row['concept_split']['beca'], $amounts['beca']);
        $this->assertSame($row['concept_split']['apoyo'], $amounts['apoyo']);
        $this->assertSame(
            $this->cents($row['total_to_pay']),
            $this->cents($amounts['beca']) + $this->cents($amounts['apoyo'])
        );
    }

    /** @test */
    public function a_zero_concept_is_omitted(): void
    {
        $refrend = $this->refrend([
            'snapshot_monto_apoyo'  => null,
            'snapshot_gross_amount' => 2000.00,
            'discount_amount'       => 300.00,
        ]);

        $this->assertSame(['beca' => '1700.00'], $this->service()->forRefrend($refrend->id));
    }

    /** @test */
    public function nothing_to_pay_returns_an_empty_split(): void
    {
        $refrend = $this->refrend(['discount_amount' => 2200.00, 'final_amount' => 0.00]);

        // Raw total is 0.00 and TELMEX has no coverage: no floor, no concept.
        $refrend->update(['snapshot_scholarship_type' => ScholarshipType::TELMEX->value]);

        $this->assertSame([], $this->service()->forRefrend($refrend->id));
    }

    /** @test */
    public function the_floor_cent_mirrors_rows_and_lands_in_beca(): void
    {
        // IU rows at 0.00 floor to 0.01 (R6: the manual
        // flow shares the displayed split, so it mirrors rows()).
        $refrend = $this->refrend(['discount_amount' => 2200.00, 'final_amount' => 0.00]);

        $amounts = $this->service()->forRefrend($refrend->id);
        $row     = $this->rowsSplit($refrend->id);

        $this->assertSame($row['concept_split']['beca'], $amounts['beca']);
    }
}
