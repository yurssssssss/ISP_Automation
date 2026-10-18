<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Fiber '.$this->faker->unique()->numberBetween(10, 999),
            'speed_mbps' => 20,
            'price' => 999,
            'mikrotik_profile' => '20M',
        ];
    }
}
