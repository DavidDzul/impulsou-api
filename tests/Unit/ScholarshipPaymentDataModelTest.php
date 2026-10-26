<?php

namespace Tests\Unit;

use App\Enums\PaymentMethod;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the explicit `$table` assignment (design D1/1a.3 — Eloquent's
 * default pluralization of `ScholarshipPaymentData` would otherwise resolve
 * to `scholarship_payment_datas`, which is wrong) and the user()/paymentData()
 * relationship round-trip (design D6 file-changes table, User::paymentData()).
 */
class ScholarshipPaymentDataModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function table_name_is_explicitly_scholarship_payment_data(): void
    {
        $this->assertSame('scholarship_payment_data', (new ScholarshipPaymentData())->getTable());
    }

    /** @test */
    public function fillable_covers_the_data_columns_and_user_id(): void
    {
        $this->assertEqualsCanonicalizing(
            ['user_id', 'payment_method', 'bank_name', 'account_number', 'curp', 'rfc', 'cheque_beneficiary'],
            (new ScholarshipPaymentData())->getFillable()
        );
    }

    /** @test */
    public function user_relation_resolves_the_owning_user(): void
    {
        $user = User::factory()->create();

        $paymentData = ScholarshipPaymentData::create([
            'user_id'        => $user->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => str_repeat('A', 18),
        ]);

        $this->assertTrue($paymentData->user->is($user));
    }

    /** @test */
    public function user_payment_data_relation_resolves_the_row(): void
    {
        $user = User::factory()->create();

        $paymentData = ScholarshipPaymentData::create([
            'user_id'        => $user->id,
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => str_repeat('A', 18),
        ]);

        $this->assertTrue($user->paymentData->is($paymentData));
    }

    /** @test */
    public function user_has_no_payment_data_when_no_row_exists(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->paymentData);
    }

    /** @test */
    public function payment_method_defaults_to_transfer_and_casts_to_enum(): void
    {
        $paymentData = ScholarshipPaymentData::factory()->create()->fresh();

        $this->assertSame(PaymentMethod::TRANSFER, $paymentData->payment_method);
        $this->assertSame(PaymentMethod::TRANSFER, (new ScholarshipPaymentData())->payment_method);
    }

    /** @test */
    public function cheque_factory_state_has_no_bank_data(): void
    {
        $paymentData = ScholarshipPaymentData::factory()->cheque()->create()->fresh();

        $this->assertSame(PaymentMethod::CHEQUE, $paymentData->payment_method);
        $this->assertNull($paymentData->bank_name);
        $this->assertNull($paymentData->account_number);
    }

    /** @test */
    public function refrend_accepts_cheque_columns_and_casts_them(): void
    {
        $fillable = (new ScholarshipRefrend())->getFillable();
        foreach (['paid_payment_method', 'cheque_number', 'cheque_delivered_at', 'cheque_notes'] as $col) {
            $this->assertContains($col, $fillable);
        }

        $casts = (new ScholarshipRefrend())->getCasts();
        $this->assertSame(PaymentMethod::class, $casts['paid_payment_method']);
        $this->assertSame('date', $casts['cheque_delivered_at']);
    }
}
