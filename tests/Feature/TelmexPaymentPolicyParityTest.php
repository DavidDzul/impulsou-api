<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\User;
use App\Support\Scholarship\TelmexPaymentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/telmex-pendiente-lote: the SQL form (applyBatchCandidacy) and the
 * per-object form (isBatchCandidate) of Filter A must agree on every
 * combination, and applyNonCandidacy must be the exact complement.
 */
class TelmexPaymentPolicyParityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, array{type: string, increase: ?float, coverage: bool, pending: float, advance: float}> */
    private function matrix(): array
    {
        $cases = [];
        foreach ([ScholarshipType::IU->value, ScholarshipType::TELMEX_IU->value, ScholarshipType::TELMEX->value] as $type) {
            foreach ([null, 0.0, 500.0] as $increase) {
                foreach ([false, true] as $coverage) {
                    foreach ([0.0, 200.0] as $pending) {
                        foreach ([0.0, 50.0] as $advance) {
                            $cases[] = compact('type', 'increase', 'coverage', 'pending', 'advance');
                        }
                    }
                }
            }
        }

        return $cases;
    }

    /** @return array<int, array<string, mixed>> keyed by refrend id */
    private function seedMatrix(): array
    {
        $byId = [];
        foreach ($this->matrix() as $case) {
            $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

            $coverageId = null;
            if ($case['coverage']) {
                $coverageId = TelmexCoverage::create([
                    'user_id'                        => $user->id,
                    'scholarship_type_at_activation' => ScholarshipType::TELMEX_IU->value,
                    'start_period'                   => '2026-01-01',
                    'end_period'                     => null,
                    'status'                         => 'ACTIVA',
                ])->id;
            }

            $refrend = ScholarshipRefrend::create([
                'user_id'                            => $user->id,
                'period_year'                        => 2026,
                'period_month'                       => 5,
                'refrend_type'                       => RefrendType::NORMAL->value,
                'status'                             => 'DRAFT',
                'workflow_status'                    => 'LISTO_PARA_PAGO',
                'base_amount'                        => 0,
                'discount_percentage'                => 0,
                'discount_amount'                    => 0,
                'final_amount'                       => 0,
                'snapshot_name'                      => 'Parity',
                'snapshot_campus'                    => 'MERIDA',
                'snapshot_scholarship_type'          => $case['type'],
                'snapshot_temporary_increase_amount' => $case['increase'],
                'snapshot_telmex_coverage_id'        => $coverageId,
                'amount_pending_from_previous'       => $case['pending'],
                'advance_payment_amount'             => $case['advance'],
            ]);

            $byId[$refrend->id] = $case + ['coverage_id' => $coverageId];
        }

        return $byId;
    }

    /** @test */
    public function sql_candidacy_matches_the_per_object_filter_for_every_combination(): void
    {
        $cases = $this->seedMatrix();

        $query = DB::table('scholarship_refrends as r');
        TelmexPaymentPolicy::applyBatchCandidacy($query, 'r');
        $sqlIds = $query->pluck('r.id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $phpIds = collect($cases)
            ->filter(fn (array $c) => TelmexPaymentPolicy::isBatchCandidate(
                $c['type'], $c['increase'], $c['coverage_id'], $c['pending'], $c['advance']
            ))
            ->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertCount(count($cases), $cases);
        $this->assertSame($phpIds, $sqlIds);
    }

    /** @test */
    public function non_candidacy_is_the_exact_complement_of_candidacy_for_telmex(): void
    {
        $cases = $this->seedMatrix();

        $candidates = DB::table('scholarship_refrends as r');
        TelmexPaymentPolicy::applyBatchCandidacy($candidates, 'r');
        $candidateIds = $candidates->pluck('r.id')->map(fn ($id) => (int) $id)->all();

        $non = DB::table('scholarship_refrends as r');
        TelmexPaymentPolicy::applyNonCandidacy($non, 'r');
        $nonIds = $non->pluck('r.id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([], array_intersect($candidateIds, $nonIds), 'disjoint');
        $this->assertEqualsCanonicalizing(
            array_map('intval', array_keys($cases)),
            array_merge($candidateIds, $nonIds),
            'union is every row'
        );
        $this->assertNotEmpty($nonIds, 'the matrix contains pure TELMEX rows owing nothing');
    }
}
