<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\ScholarshipPaymentData;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipPaymentDataFactory extends Factory
{
    protected $model = ScholarshipPaymentData::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id'        => User::factory(),
            'bank_name'      => 'BBVA',
            'account_number' => '012345678901234567',
            'curp'           => 'ABCD010101HDFRRL09',
            'rfc'            => null,
        ];
    }

    /**
     * sdd/pago-cheque: cheque payment data carries no bank fields.
     */
    public function cheque(?string $beneficiary = null): static
    {
        return $this->state(fn () => [
            'payment_method'     => PaymentMethod::CHEQUE->value,
            'bank_name'          => null,
            'account_number'     => null,
            'cheque_beneficiary' => $beneficiary,
        ]);
    }
}
