<?php

namespace Tests\Feature;

use App\Models\ScholarshipPaymentData;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/pago-cheque, tasks 1.4/1.5 (spec R1.1-R1.6): payment method on the
 * scholarship payment data endpoints.
 */
class ScholarshipPaymentDataChequeTest extends TestCase
{
    use RefreshDatabase;

    private const URL  = '/api/admin/scholarship-payment-data';
    private const CURP = 'ABCD010101HDFRRL09';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
    }

    private function becario(): User
    {
        return User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
    }

    private function store(array $payload)
    {
        return $this->actingAs($this->admin)->postJson(self::URL, $payload);
    }

    private function update(User $becario, array $payload)
    {
        return $this->actingAs($this->admin)->putJson(self::URL . "/{$becario->id}", $payload);
    }

    // -- store ---------------------------------------------------------

    /** @test */
    public function store_cheque_without_bank_data_returns_201_with_null_bank_fields(): void
    {
        $becario = $this->becario();

        $response = $this->store([
            'user_id'            => $becario->id,
            'payment_method'     => 'CHEQUE',
            'curp'               => self::CURP,
            'cheque_beneficiary' => 'Madre',
        ]);

        $response->assertStatus(201)->assertJson(['res' => true, 'data' => [
            'payment_method'     => 'CHEQUE',
            'cheque_beneficiary' => 'Madre',
            'bank_name'          => null,
            'account_number'     => null,
        ]]);
    }

    /** @test */
    public function store_without_payment_method_defaults_to_transfer(): void
    {
        $becario = $this->becario();

        $this->store([
            'user_id' => $becario->id, 'bank_name' => 'BBVA',
            'account_number' => '012345678901234567', 'curp' => self::CURP,
        ])->assertStatus(201)->assertJsonPath('data.payment_method', 'TRANSFER');
    }

    /** @test */
    public function store_transfer_requires_bank_fields(): void
    {
        $becario = $this->becario();

        $this->store(['user_id' => $becario->id, 'payment_method' => 'TRANSFER', 'account_number' => '012345678901234567', 'curp' => self::CURP])
            ->assertStatus(422)->assertJsonValidationErrors('bank_name');

        $this->store(['user_id' => $becario->id, 'payment_method' => 'TRANSFER', 'bank_name' => 'BBVA', 'curp' => self::CURP])
            ->assertStatus(422)->assertJsonValidationErrors('account_number');
    }

    /** @test */
    public function store_cheque_requires_curp(): void
    {
        $becario = $this->becario();

        $this->store(['user_id' => $becario->id, 'payment_method' => 'CHEQUE'])
            ->assertStatus(422)->assertJsonValidationErrors('curp');
    }

    /** @test */
    public function store_rejects_invalid_payment_method_and_long_beneficiary(): void
    {
        $becario = $this->becario();

        $this->store(['user_id' => $becario->id, 'payment_method' => 'CASH', 'curp' => self::CURP])
            ->assertStatus(422)->assertJsonValidationErrors('payment_method');

        $this->store(['user_id' => $becario->id, 'payment_method' => 'CHEQUE', 'curp' => self::CURP, 'cheque_beneficiary' => str_repeat('a', 151)])
            ->assertStatus(422)->assertJsonValidationErrors('cheque_beneficiary');
    }

    /** @test */
    public function store_cheque_ignores_bank_data_sent_by_the_client(): void
    {
        $becario = $this->becario();

        $this->store([
            'user_id' => $becario->id, 'payment_method' => 'CHEQUE', 'curp' => self::CURP,
            'bank_name' => 'X', 'account_number' => '123',
        ])->assertStatus(201);

        $this->assertDatabaseHas('scholarship_payment_data', [
            'user_id' => $becario->id, 'bank_name' => null, 'account_number' => null,
        ]);
    }

    /** @test */
    public function store_transfer_discards_the_cheque_beneficiary(): void
    {
        $becario = $this->becario();

        $this->store([
            'user_id' => $becario->id, 'payment_method' => 'TRANSFER', 'curp' => self::CURP,
            'bank_name' => 'BBVA', 'account_number' => '012345678901234567', 'cheque_beneficiary' => 'Madre',
        ])->assertStatus(201)->assertJsonPath('data.cheque_beneficiary', null);
    }

    // -- update --------------------------------------------------------

    /** @test */
    public function update_transfer_to_cheque_nulls_bank_fields(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $becario->id]);

        $this->update($becario, ['payment_method' => 'CHEQUE', 'cheque_beneficiary' => 'Madre'])
            ->assertStatus(200)
            ->assertJson(['data' => ['payment_method' => 'CHEQUE', 'cheque_beneficiary' => 'Madre', 'bank_name' => null, 'account_number' => null]]);
    }

    /** @test */
    public function update_cheque_to_transfer_requires_bank_fields(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->cheque('Madre')->create(['user_id' => $becario->id]);

        $this->update($becario, ['payment_method' => 'TRANSFER'])
            ->assertStatus(422)->assertJsonValidationErrors(['bank_name', 'account_number']);

        $this->update($becario, ['payment_method' => 'TRANSFER', 'bank_name' => 'BBVA', 'account_number' => '012345678901234567'])
            ->assertStatus(200)
            ->assertJson(['data' => ['payment_method' => 'TRANSFER', 'bank_name' => 'BBVA', 'cheque_beneficiary' => null]]);
    }

    /** @test */
    public function update_cheque_with_bank_name_sent_stores_null(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->cheque()->create(['user_id' => $becario->id]);

        $this->update($becario, ['bank_name' => 'X'])->assertStatus(200)->assertJsonPath('data.bank_name', null);
    }

    /** @test */
    public function update_rejects_invalid_payment_method(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $becario->id]);

        $this->update($becario, ['payment_method' => 'CASH'])
            ->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    /** @test */
    public function update_without_payment_method_keeps_the_stored_one(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->cheque('Madre')->create(['user_id' => $becario->id]);

        $this->update($becario, ['cheque_beneficiary' => 'Padre'])
            ->assertStatus(200)
            ->assertJson(['data' => ['payment_method' => 'CHEQUE', 'cheque_beneficiary' => 'Padre']]);
    }

    /** @test */
    public function update_transfer_cannot_blank_out_bank_fields(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $becario->id]);

        $this->update($becario, ['bank_name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('bank_name');
    }

    // -- show ----------------------------------------------------------

    /** @test */
    public function show_exposes_payment_method_and_beneficiary(): void
    {
        $becario = $this->becario();
        ScholarshipPaymentData::factory()->cheque('Madre')->create(['user_id' => $becario->id]);

        $this->actingAs($this->admin)->getJson(self::URL . "/{$becario->id}")
            ->assertStatus(200)
            ->assertJson(['data' => ['payment_method' => 'CHEQUE', 'cheque_beneficiary' => 'Madre', 'bank_name' => null]]);
    }
}
