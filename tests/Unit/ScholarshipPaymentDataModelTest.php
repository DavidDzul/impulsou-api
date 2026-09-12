<?php

namespace Tests\Unit;

use App\Models\ScholarshipPaymentData;
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
    public function fillable_covers_the_four_data_columns_and_user_id(): void
    {
        $this->assertEqualsCanonicalizing(
            ['user_id', 'bank_name', 'account_number', 'curp', 'rfc'],
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
}
