<?php

namespace Database\Factories;

use App\Models\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankFactory extends Factory
{
    protected $model = Bank::class;

    public function definition()
    {
        return [
            'code' => str_pad((string) $this->faker->unique()->numberBetween(800, 999), 3, '0', STR_PAD_LEFT),
            'name' => $this->faker->unique()->company(),
            'is_active' => true,
            'is_system' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
