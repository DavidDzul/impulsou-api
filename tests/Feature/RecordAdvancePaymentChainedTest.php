<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado, S1 (spec R6 S9/S10): an origin created by an
 * advance, or linked to an advance month, cannot record a new advance.
 */
class RecordAdvancePaymentChainedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        Carbon::setTestNow(Carbon::create(2026, 9, 15));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeOrigin(array $overrides = []): ScholarshipRefrend
    {
        $userId = $overrides['user_id'] ?? null;

        if ($userId === null) {
            $user = User::factory()->create([
                'user_type' => 'BEC_ACTIVE',
                'campus'    => 'MERIDA',
                'active'    => true,
            ]);
            $userId = $user->id;

            ScholarshipProfile::create([
                'user_id'             => $userId,
                'scholarship_type'    => ScholarshipType::IU->value,
                'monthly_amount'      => 2000.00,
                'monto_apoyo'         => 0,
                'reticula_start_date' => '2000-01-01',
                'reticula_end_date'   => '2099-12-31',
                'payment_start_date'  => now()->toDateString(),
                'config_set_at'       => now(),
            ]);
        }

        return ScholarshipRefrend::create(array_merge([
            'user_id'                      => $userId,
            'period_year'                  => 2026,
            'period_month'                 => 9,
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
        ], $overrides));
    }

    private function url(int $refrendId): string
    {
        return "/api/admin/scholarship-refrends/{$refrendId}/advance-payment";
    }

    private function payload(): array
    {
        return [
            'months' => [['year' => 2027, 'month' => 3]],
            'cause'  => 'Viaje académico',
        ];
    }

    /** @test */
    public function it_rejects_an_origin_created_via_advance_payment(): void
    {
        $origin = $this->makeOrigin(['created_via' => 'ADVANCE_PAYMENT']);

        $response = $this->actingAs($this->admin)->postJson($this->url($origin->id), $this->payload());

        $response->assertStatus(422);
        $response->assertJson([
            'res'  => false,
            'code' => 'ADVANCE_CHAINED_FORBIDDEN',
        ]);
        $this->assertNotEmpty($response->json('msg'));
        $this->assertSame(0, ScholarshipAdvancePayment::count());
    }

    /** @test */
    public function it_rejects_an_origin_linked_to_an_advance_month(): void
    {
        $origin = $this->makeOrigin();

        // A different refrend (another origin) advanced this origin's own month.
        $other  = $this->makeOrigin(['user_id' => $origin->user_id, 'period_month' => 8]);
        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $origin->user_id,
            'origin_refrend_id'   => $other->id,
            'origin_period_year'  => 2026,
            'origin_period_month' => 8,
            'months_count'        => 1,
            'total_amount'        => '1000.00',
            'status'              => 'ACTIVE',
        ]);
        DB::table('scholarship_advance_payment_months')->insert([
            'advance_payment_id' => $header->id,
            'user_id'            => $origin->user_id,
            'period_year'        => 2026,
            'period_month'       => 9,
            'amount'             => '1000.00',
            'refrend_id'         => $origin->id,
            'status'             => 'PENDING',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $response = $this->actingAs($this->admin)->postJson($this->url($origin->id), $this->payload());

        $response->assertStatus(422);
        $response->assertJson(['res' => false, 'code' => 'ADVANCE_CHAINED_FORBIDDEN']);
        $this->assertSame(1, ScholarshipAdvancePayment::count());
        $this->assertSame(1, ScholarshipAdvancePaymentMonth::count());
    }

    /** @test */
    public function it_keeps_the_existing_error_when_the_origin_already_has_an_advance(): void
    {
        $origin = $this->makeOrigin();

        $first = $this->actingAs($this->admin)->postJson($this->url($origin->id), $this->payload());
        $first->assertStatus(200);

        $second = $this->actingAs($this->admin)->postJson($this->url($origin->id), [
            'months' => [['year' => 2027, 'month' => 4]],
            'cause'  => 'Otro viaje',
        ]);

        $second->assertStatus(422);
        $second->assertJson(['res' => false, 'msg' => 'Este refrendo ya tiene un pago adelantado registrado.']);
        $this->assertArrayNotHasKey('code', $second->json());
    }

    /** @test */
    public function it_locks_the_origin_before_reading_the_profile_inside_the_transaction(): void
    {
        $origin = $this->makeOrigin();

        $selects = [];
        DB::listen(function ($query) use (&$selects) {
            if (stripos($query->sql, 'select') === 0) {
                $selects[] = $query->sql;
            }
        });

        $this->actingAs($this->admin)->postJson($this->url($origin->id), $this->payload())->assertStatus(200);

        $originIdx  = null;
        $profileIdx = null;
        foreach ($selects as $i => $sql) {
            if ($originIdx === null && str_contains($sql, 'from "scholarship_refrends" where "scholarship_refrends"."id"')) {
                $originIdx = $i;
            }
            if ($profileIdx === null && str_contains($sql, 'from "scholarship_profiles"')) {
                $profileIdx = $i;
            }
        }

        $this->assertNotNull($originIdx, 'origin lock query not found');
        $this->assertNotNull($profileIdx, 'profile query not found');
        $this->assertLessThan($profileIdx, $originIdx);
    }
}
