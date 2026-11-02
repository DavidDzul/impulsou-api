<?php

namespace Tests\Feature;

use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Bank;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/catalogo-bancos R5/R6 at endpoint level: the BBVA batch only ever sees
 * BBVA transfers; other-bank, cheque and bankless becarios stay out of it.
 */
class ScholarshipPaymentBankChannelEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
    }

    private function makeRefrend(array $spd, string $name): ScholarshipRefrend
    {
        $user = User::factory()->create([
            'user_type' => 'BEC_ACTIVE', 'campus' => self::CAMPUS, 'active' => true,
            'enrollment' => 'MAT-' . random_int(100000, 999999),
        ]);

        ScholarshipPaymentData::factory()->create(array_merge(
            ['user_id' => $user->id, 'account_number' => '0123456789', 'rfc' => 'PEPJ800101ABC'],
            $spd
        ));

        return ScholarshipRefrend::create([
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
        ]);
    }

    private function banorte(): array
    {
        return [
            'bank_id'        => Bank::where('code', '072')->value('id'),
            'bank_name'      => 'Banorte',
            'account_number' => '072000000000000009',
        ];
    }

    private function index()
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/scholarship-payments?' . http_build_query([
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH, 'batch_type' => 'NORMAL',
        ]));
    }

    /** @test */
    public function index_batch_rows_hold_only_bbva_and_bankless_transfers_and_cheques_stay_apart(): void
    {
        $this->makeRefrend([], 'A bbva');
        $this->makeRefrend($this->banorte(), 'B banorte');
        $this->makeRefrend(['bank_id' => null, 'bank_name' => null], 'C sin banco');
        $this->makeRefrend(['payment_method' => 'CHEQUE', 'bank_id' => null, 'bank_name' => null, 'account_number' => null], 'D cheque');

        $response = $this->index()->assertOk();

        $this->assertSame(['A bbva', 'C sin banco'], array_column($response->json('data.rows'), 'snapshot_name'));
        $this->assertSame(['D cheque'], array_column($response->json('data.cheque_rows'), 'snapshot_name'));

        $bankless = collect($response->json('data.rows'))->firstWhere('snapshot_name', 'C sin banco');
        $this->assertFalse($bankless['is_payable']);
        $this->assertContains('MISSING_BANK', array_column($bankless['blocking_reasons'], 'code'));
    }

    /** @test */
    public function process_pays_only_the_bbva_rows_and_never_touches_other_bank_becarios(): void
    {
        $bbva  = $this->makeRefrend([], 'A bbva');
        $other = $this->makeRefrend($this->banorte(), 'B banorte');

        $response = $this->actingAs($this->admin)->postJson('/api/admin/scholarship-payments/process', [
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH,
            'batch_type' => 'NORMAL', 'expected_count' => 1, 'expected_total' => '1000.00',
        ]);

        $response->assertOk();
        $this->assertSame(['A bbva'], array_column($response->json('data.rows'), 'snapshot_name'));
        $this->assertNotNull($bbva->fresh()->payment_batch_id);
        $this->assertNull($other->fresh()->payment_batch_id);
        $this->assertNull($other->fresh()->locked_at);
    }

    /** @test */
    public function process_is_blocked_while_a_transfer_becario_has_no_bank(): void
    {
        $this->makeRefrend([], 'A bbva');
        $this->makeRefrend(['bank_id' => null, 'bank_name' => null], 'C sin banco');

        $this->actingAs($this->admin)->postJson('/api/admin/scholarship-payments/process', [
            'campus' => self::CAMPUS, 'period_year' => self::YEAR, 'period_month' => self::MONTH,
            'batch_type' => 'NORMAL', 'expected_count' => 2, 'expected_total' => '2000.00',
        ])->assertStatus(422)->assertJsonPath('data.blocking_rows.0.snapshot_name', 'C sin banco');
    }
}
