<?php

namespace Database\Factories;

use App\Models\ScholarshipAdvancePayment;
use App\Models\ScholarshipAdvancePaymentMonth;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScholarshipAdvancePaymentMonthFactory extends Factory
{
    protected $model = ScholarshipAdvancePaymentMonth::class;

    /**
     * Define the model's default state.
     *
     * Builds its own header (and a matching origin refrend) rather than
     * using a nested factory-relation attribute, so the child's user_id
     * always matches its header's user_id, and a distinct future refrend
     * (period_year/period_month) is available to satisfy the unique(refrend_id)
     * / unique(user_id, period_year, period_month) constraints out of the box.
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

        $header = ScholarshipAdvancePayment::create([
            'user_id'             => $user->id,
            'origin_refrend_id'   => $originRefrend->id,
            'origin_period_year'  => $originRefrend->period_year,
            'origin_period_month' => $originRefrend->period_month,
            'months_count'        => 1,
            'total_amount'        => 2000.00,
        ]);

        $future = now()->addMonths(3);

        $futureRefrend = ScholarshipRefrend::create([
            'user_id'       => $user->id,
            'period_year'   => $future->year,
            'period_month'  => $future->month,
            'base_amount'   => 2000,
            'final_amount'  => 2000,
            'snapshot_name' => $user->name ?? 'Test User',
        ]);

        return [
            'advance_payment_id'      => $header->id,
            'user_id'                 => $user->id,
            'period_year'             => $futureRefrend->period_year,
            'period_month'            => $futureRefrend->period_month,
            'amount'                  => 2000.00,
            'refrend_id'              => $futureRefrend->id,
            'status'                  => 'PENDING',
            'reached_at'              => null,
            'settled_resolution_type' => null,
            'divergence_reason'       => null,
        ];
    }
}
