<?php

namespace Database\Factories;

use App\Models\ScholarshipRefrendManualPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipRefrendManualPaymentFactory extends Factory
{
    protected $model = ScholarshipRefrendManualPayment::class;

    public function definition()
    {
        $reference = 'REF-' . $this->faker->unique()->numerify('######');

        return [
            'concept'              => ScholarshipRefrendManualPayment::CONCEPT_BECA,
            'method'               => 'CHEQUE',
            'reference'            => $reference,
            'reference_normalized' => mb_strtolower($reference),
            'amount'               => '800.00',
            'paid_at'              => '2026-05-20',
            'notes'                => null,
        ];
    }

    /** Keeps the normalized column in step with an overridden reference. */
    public function configure()
    {
        return $this->afterMaking(function (ScholarshipRefrendManualPayment $payment) {
            $payment->reference_normalized = mb_strtolower(trim((string) $payment->reference));
        });
    }
}
