<?php

namespace Database\Factories;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipProfileFactory extends Factory
{
    protected $model = ScholarshipProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'user_id'                    => User::factory(),
            'scholarship_type'           => 'IU',
            'monthly_amount'             => 2500,
            'monto_apoyo'                => null,
            'advance_payment_eligible'   => false,
            'active_discount_percentage' => null,
            'discount_reason'            => null,
            'discount_valid_until'       => null,
            // NOT NULL on the SQLite test schema — the column-drop migration
            // is skipped for the sqlite driver (see
            // RemovePaymentDatesFromScholarshipProfilesTable). Kept here only
            // to satisfy that legacy constraint in tests; unused elsewhere.
            'payment_start_date'         => now()->toDateString(),
        ];
    }
}
