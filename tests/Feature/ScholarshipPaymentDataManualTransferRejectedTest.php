<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\ScholarshipPaymentData;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/catalogo-bancos R7.4: MANUAL_TRANSFER is snapshot-only; clients can
 * never store it as a becario's payment_method.
 */
class ScholarshipPaymentDataManualTransferRejectedTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/scholarship-payment-data';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
    }

    /** @test */
    public function store_rejects_manual_transfer_as_payment_method(): void
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        $this->actingAs($this->admin)->postJson(self::URL, [
            'user_id'        => $user->id,
            'payment_method' => 'MANUAL_TRANSFER',
            'bank_id'        => Bank::where('code', '012')->value('id'),
            'account_number' => '1234567890',
            'curp'           => 'ABCD010101HDFRRL09',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    /** @test */
    public function update_rejects_manual_transfer_as_payment_method(): void
    {
        $data = ScholarshipPaymentData::factory()->create();

        $this->actingAs($this->admin)
            ->putJson(self::URL.'/'.$data->user_id, ['payment_method' => 'MANUAL_TRANSFER'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }
}
