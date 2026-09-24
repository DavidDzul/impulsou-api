<?php

namespace Database\Factories;

use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipAdvancePaymentFactory extends Factory
{
    protected $model = ScholarshipAdvancePayment::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $user = User::factory()->create();

        $originRefrend = ScholarshipRefrend::create([
            'user_id'       => $user->id,
            'period_year'   => now()->year,
            'period_month'  => now()->month,
            'base_amount'   => 2000,
            'final_amount'  => 2000,
            'snapshot_name' => $user->name ?? 'Test User',
        ]);

        return [
            'user_id'             => $user->id,
            'origin_refrend_id'   => $originRefrend->id,
            'origin_period_year'  => $originRefrend->period_year,
            'origin_period_month' => $originRefrend->period_month,
            'months_count'        => 1,
            'total_amount'        => 2000.00,
            'status'              => 'ACTIVE',
            'cause'               => null,
            'notes'               => null,
            'created_by_id'       => null,
        ];
    }
}
