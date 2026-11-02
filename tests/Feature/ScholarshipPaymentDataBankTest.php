<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\ScholarshipPaymentData;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/catalogo-bancos R3: bank_id + account/CLABE validation on payment data.
 */
class ScholarshipPaymentDataBankTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/scholarship-payment-data';
    private const BANORTE_CLABE = '072000000000000009';
    private const BBVA_CLABE = '012180000112345671';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->admin->assignRole('ROOT_ADMINISTRATION');
    }

    private function bank(string $code): Bank
    {
        return Bank::where('code', $code)->firstOrFail();
    }

    private function becario(): User
    {
        return User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);
    }

    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'user_id'        => $user->id,
            'bank_id'        => $this->bank('012')->id,
            'account_number' => '1234567890',
            'curp'           => 'ABCD010101HDFRRL09',
        ], $overrides);
    }

    /** @test */
    public function store_bbva_accepts_a_10_digit_account_and_sets_bank_name_from_the_bank(): void
    {
        $user = $this->becario();

        $this->actingAs($this->admin)->postJson(self::URL, $this->payload($user, ['bank_name' => 'spoofed']))
            ->assertStatus(201)
            ->assertJsonPath('data.bank_id', $this->bank('012')->id)
            ->assertJsonPath('data.bank_name', 'BBVA');
    }

    /** @test */
    public function store_bbva_accepts_a_9_digit_account(): void
    {
        $this->actingAs($this->admin)
            ->postJson(self::URL, $this->payload($this->becario(), ['account_number' => '123456789']))
            ->assertStatus(201);
    }

    /** @test */
    public function store_bbva_rejects_an_account_that_is_not_9_or_10_digits(): void
    {
        $this->actingAs($this->admin)
            ->postJson(self::URL, $this->payload($this->becario(), ['account_number' => '12345678']))
            ->assertStatus(422)
            ->assertJsonPath('errors.account_number.0', 'La cuenta debe tener 9 o 10 dígitos.');
    }

    /** @test */
    public function store_requires_a_bank_for_transfer(): void
    {
        $payload = $this->payload($this->becario());
        unset($payload['bank_id']);

        $this->actingAs($this->admin)->postJson(self::URL, $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.bank_id.0', 'El banco es obligatorio para transferencias.');
    }

    /** @test */
    public function store_non_bbva_accepts_a_valid_clabe_with_matching_prefix(): void
    {
        $banorte = $this->bank('072');

        $this->actingAs($this->admin)
            ->postJson(self::URL, $this->payload($this->becario(), [
                'bank_id' => $banorte->id, 'account_number' => self::BANORTE_CLABE,
            ]))
            ->assertStatus(201)
            ->assertJsonPath('data.bank_name', $banorte->name);
    }

    /** @test */
    public function store_non_bbva_rejects_a_10_digit_account(): void
    {
        $this->actingAs($this->admin)
            ->postJson(self::URL, $this->payload($this->becario(), [
                'bank_id' => $this->bank('072')->id, 'account_number' => '1234567890',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.account_number.0', 'La CLABE no es válida.');
    }

    /** @test */
    public function store_non_bbva_rejects_a_clabe_with_a_bad_check_digit(): void
    {
        $this->actingAs($this->admin)
            ->postJson(self::URL, $this->payload($this->becario(), [
                'bank_id' => $this->bank('072')->id, 'account_number' => '072000000000000008',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.account_number.0', 'La CLABE no es válida.');
    }

    /** @test */
    public function store_rejects_a_clabe_whose_prefix_belongs_to_another_bank(): void
    {
        $this->actingAs($this->admin)
            ->postJson(self::URL, $this->payload($this->becario(), [
                'bank_id' => $this->bank('072')->id, 'account_number' => self::BBVA_CLABE,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.account_number.0', 'La CLABE no corresponde a Banorte.');
    }

    /** @test */
    public function store_rejects_an_inactive_bank(): void
    {
        $banorte = $this->bank('072');
        $banorte->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->postJson(self::URL, $this->payload($this->becario(), [
                'bank_id' => $banorte->id, 'account_number' => self::BANORTE_CLABE,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.bank_id.0', 'El banco seleccionado no está disponible.');
    }

    /** @test */
    public function store_rejects_an_unknown_bank(): void
    {
        $this->actingAs($this->admin)
            ->postJson(self::URL, $this->payload($this->becario(), ['bank_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bank_id']);
    }

    /** @test */
    public function store_cheque_ignores_bank_fields(): void
    {
        $user = $this->becario();

        $this->actingAs($this->admin)->postJson(self::URL, [
            'user_id'        => $user->id,
            'payment_method' => 'CHEQUE',
            'bank_id'        => $this->bank('012')->id,
            'account_number' => '1234567890',
            'curp'           => 'ABCD010101HDFRRL09',
        ])->assertStatus(201)->assertJsonPath('data.bank_id', null);
    }

    /** @test */
    public function update_keeps_an_inactive_bank_when_it_is_unchanged(): void
    {
        $banorte = $this->bank('072');
        $user = $this->becario();
        ScholarshipPaymentData::factory()->create([
            'user_id' => $user->id, 'bank_id' => $banorte->id,
            'bank_name' => 'Banorte', 'account_number' => self::BANORTE_CLABE,
        ]);
        $banorte->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->putJson(self::URL.'/'.$user->id, [
                'bank_id' => $banorte->id, 'account_number' => self::BANORTE_CLABE,
                'curp' => 'ABCD010101HDFRRL10',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.bank_id', $banorte->id);
    }

    /** @test */
    public function update_unrelated_field_does_not_revalidate_the_stored_account(): void
    {
        $user = $this->becario();
        ScholarshipPaymentData::factory()->create([
            'user_id' => $user->id, 'bank_id' => null, 'bank_name' => 'Legacy', 'account_number' => '12345',
        ]);

        $this->actingAs($this->admin)
            ->putJson(self::URL.'/'.$user->id, ['rfc' => null])
            ->assertStatus(200);
    }

    /** @test */
    public function update_rejects_switching_to_an_inactive_bank(): void
    {
        $user = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $user->id]);
        $banorte = $this->bank('072');
        $banorte->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->putJson(self::URL.'/'.$user->id, [
                'bank_id' => $banorte->id, 'account_number' => self::BANORTE_CLABE,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.bank_id.0', 'El banco seleccionado no está disponible.');
    }

    /** @test */
    public function update_changing_bank_validates_the_stored_account_against_it(): void
    {
        $user = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $user->id, 'account_number' => '1234567890']);

        $this->actingAs($this->admin)
            ->putJson(self::URL.'/'.$user->id, ['bank_id' => $this->bank('072')->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.account_number.0', 'La CLABE no es válida.');
    }

    /** @test */
    public function update_switching_to_non_bbva_with_a_valid_clabe_sets_bank_name(): void
    {
        $user = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $user->id]);

        $this->actingAs($this->admin)
            ->putJson(self::URL.'/'.$user->id, [
                'bank_id' => $this->bank('072')->id, 'account_number' => self::BANORTE_CLABE,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.bank_name', 'Banorte');
    }

    /** @test */
    public function update_to_cheque_clears_the_bank(): void
    {
        $user = $this->becario();
        ScholarshipPaymentData::factory()->create(['user_id' => $user->id]);

        $this->actingAs($this->admin)
            ->putJson(self::URL.'/'.$user->id, ['payment_method' => 'CHEQUE'])
            ->assertStatus(200)
            ->assertJsonPath('data.bank_id', null)
            ->assertJsonPath('data.bank_name', null);
    }

    /** @test */
    public function update_cheque_to_transfer_requires_a_bank(): void
    {
        $user = $this->becario();
        ScholarshipPaymentData::factory()->cheque('Ana')->create(['user_id' => $user->id]);

        $this->actingAs($this->admin)
            ->putJson(self::URL.'/'.$user->id, ['payment_method' => 'TRANSFER', 'account_number' => '1234567890'])
            ->assertStatus(422)
            ->assertJsonPath('errors.bank_id.0', 'El banco es obligatorio para transferencias.');
    }
}
