<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Bank;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendManualPayment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/bank-file-split-other-banks-cheques PR6 (spec C1-C3): index() carries
 * `concept_totals`, the combined Beca/Apoyo money across BBVA, other-bank
 * transfers and cheques. Ready and paid are never added together.
 */
class ScholarshipPaymentConceptTotalsTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;
    private const CLABE  = '072000000000000009';

    private User $admin;

    private int $enrollmentSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
    }

    private function makeRefrend(string $channel, array $overrides = [], string $name = 'Becario'): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type'  => 'BEC_ACTIVE',
            'campus'     => self::CAMPUS,
            'active'     => true,
            'enrollment' => sprintf('MAT-CT-%06d', ++$this->enrollmentSeq),
        ]);

        if ($channel === 'cheque') {
            ScholarshipPaymentData::factory()->cheque()->create(['user_id' => $user->id]);
        } elseif ($channel === 'manual') {
            ScholarshipPaymentData::factory()->create([
                'user_id'        => $user->id,
                'bank_id'        => Bank::where('code', '072')->value('id'),
                'bank_name'      => 'Banorte',
                'account_number' => self::CLABE,
            ]);
        } else {
            ScholarshipPaymentData::factory()->create(['user_id' => $user->id, 'account_number' => '0123456789', 'rfc' => 'PEPJ800101ABC']);
        }

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $user->id,
            'period_year'                  => self::YEAR,
            'period_month'                 => self::MONTH,
            'refrend_type'                 => RefrendType::NORMAL->value,
            'status'                       => 'DRAFT',
            'workflow_status'              => 'LISTO_PARA_PAGO',
            'base_amount'                  => 1000.00,
            'discount_percentage'          => 0,
            'discount_amount'              => 0,
            'final_amount'                 => 1000.00,
            'amount_pending_from_previous' => 0,
            'snapshot_name'                => $name,
            'snapshot_generation_id'       => 1,
            'snapshot_campus'              => self::CAMPUS,
            'snapshot_scholarship_type'    => ScholarshipType::IU->value,
        ], $overrides));
    }

    /** 1500 Beca / 200 Apoyo (total 1700). */
    private function twoConcept(): array
    {
        return [
            'base_amount'           => 1500.00,
            'snapshot_monto_apoyo'  => 200.00,
            'snapshot_gross_amount' => 1700.00,
            'discount_amount'       => 0,
            'final_amount'          => 1700.00,
        ];
    }

    private function paidOverrides(PaymentMethod $method): array
    {
        return [
            'status'              => 'PAID',
            'workflow_status'     => 'CLOSED',
            'locked_at'           => now(),
            'paid_payment_method' => $method->value,
        ];
    }

    private function index()
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/scholarship-payments?' . http_build_query([
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH, 'batch_type' => 'NORMAL',
        ]));
    }

    private function child(ScholarshipRefrend $refrend, string $method, string $concept, string $amount, string $ref): void
    {
        ScholarshipRefrendManualPayment::factory()->create([
            'refrend_id' => $refrend->id,
            'method'     => $method,
            'concept'    => $concept,
            'amount'     => $amount,
            'reference'  => $ref,
        ]);
    }

    private function zero(): array
    {
        return ['beca' => '0.00', 'apoyo' => '0.00', 'total' => '0.00'];
    }

    /** @test */
    public function empty_period_returns_every_bucket_at_zero(): void
    {
        $totals = $this->index()->assertOk()->json('data.concept_totals');

        foreach (['bbva', 'manual_transfer', 'cheque'] as $channel) {
            $this->assertSame($this->zero(), $totals['channels'][$channel]['ready'], $channel);
            $this->assertSame($this->zero(), $totals['channels'][$channel]['paid'], $channel);
        }
        $this->assertSame($this->zero(), $totals['overall']['ready']);
        $this->assertSame($this->zero(), $totals['overall']['paid']);
    }

    /** @test */
    public function ready_money_is_combined_per_channel_and_overall(): void
    {
        $this->makeRefrend('bbva', [], 'B1');
        $this->makeRefrend('manual', $this->twoConcept(), 'M1');
        $this->makeRefrend('cheque', [], 'C1');

        $totals = $this->index()->assertOk()->json('data.concept_totals');

        $this->assertSame(['beca' => '1000.00', 'apoyo' => '0.00', 'total' => '1000.00'], $totals['channels']['bbva']['ready']);
        $this->assertSame(['beca' => '1500.00', 'apoyo' => '200.00', 'total' => '1700.00'], $totals['channels']['manual_transfer']['ready']);
        $this->assertSame(['beca' => '1000.00', 'apoyo' => '0.00', 'total' => '1000.00'], $totals['channels']['cheque']['ready']);
        $this->assertSame(['beca' => '3500.00', 'apoyo' => '200.00', 'total' => '3700.00'], $totals['overall']['ready']);
        $this->assertSame($this->zero(), $totals['overall']['paid']);
    }

    /** @test */
    public function paid_manual_money_comes_from_frozen_child_rows_and_never_mixes_with_ready(): void
    {
        $cheque = $this->makeRefrend('cheque', $this->paidOverrides(PaymentMethod::CHEQUE) + $this->twoConcept(), 'PC');
        // Frozen 800/200 differs from the live 1500/200 split.
        $this->child($cheque, 'CHEQUE', 'beca', '800.00', 'CH-B');
        $this->child($cheque, 'CHEQUE', 'apoyo', '200.00', 'CH-A');

        $manual = $this->makeRefrend('manual', $this->paidOverrides(PaymentMethod::MANUAL_TRANSFER), 'PM');
        $this->child($manual, 'MANUAL_TRANSFER', 'beca', '1000.00', 'TR-B');

        $this->makeRefrend('cheque', [], 'ReadyC');

        $totals = $this->index()->assertOk()->json('data.concept_totals');

        $this->assertSame(['beca' => '800.00', 'apoyo' => '200.00', 'total' => '1000.00'], $totals['channels']['cheque']['paid']);
        $this->assertSame(['beca' => '1000.00', 'apoyo' => '0.00', 'total' => '1000.00'], $totals['channels']['cheque']['ready']);
        $this->assertSame(['beca' => '1000.00', 'apoyo' => '0.00', 'total' => '1000.00'], $totals['channels']['manual_transfer']['paid']);
        $this->assertSame($this->zero(), $totals['channels']['manual_transfer']['ready']);
        $this->assertSame(['beca' => '1800.00', 'apoyo' => '200.00', 'total' => '2000.00'], $totals['overall']['paid']);
        $this->assertSame(['beca' => '1000.00', 'apoyo' => '0.00', 'total' => '1000.00'], $totals['overall']['ready']);
    }

    /** @test */
    public function paid_bbva_money_covers_rows_paid_in_the_batch(): void
    {
        $this->makeRefrend('bbva', [], 'B1');
        $this->makeRefrend('bbva', $this->twoConcept(), 'B2');

        $this->actingAs($this->admin)->postJson('/api/admin/scholarship-payments/process', [
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH,
            'batch_type' => 'NORMAL', 'expected_count' => 2, 'expected_total' => '2700.00',
        ])->assertSuccessful();

        $totals = $this->index()->assertOk()->json('data.concept_totals');

        $this->assertSame(['beca' => '2500.00', 'apoyo' => '200.00', 'total' => '2700.00'], $totals['channels']['bbva']['paid']);
        $this->assertSame($this->zero(), $totals['channels']['bbva']['ready']);
        $this->assertSame(['beca' => '2500.00', 'apoyo' => '200.00', 'total' => '2700.00'], $totals['overall']['paid']);
    }

    /** @test */
    public function concept_totals_do_not_add_queries_per_row(): void
    {
        $seed = function (string $tag, int $count): void {
            for ($i = 1; $i <= $count; $i++) {
                $paid = $this->makeRefrend('cheque', $this->paidOverrides(PaymentMethod::CHEQUE), "PC$tag$i");
                $this->child($paid, 'CHEQUE', 'beca', '1000.00', "CH-$tag$i");
                $this->makeRefrend('manual', [], "RM$tag$i");
                $this->makeRefrend('bbva', [], "RB$tag$i");
            }
        };

        $queries = function (): int {
            DB::flushQueryLog();
            $this->index()->assertOk();

            return count(DB::getQueryLog());
        };

        $seed('a', 2);
        DB::enableQueryLog();
        $this->index()->assertOk(); // warm-up (auth/session lookups)
        $few = $queries();

        $seed('b', 5);
        $many = $queries();
        DB::disableQueryLog();

        $this->assertSame($few, $many);
    }
}
