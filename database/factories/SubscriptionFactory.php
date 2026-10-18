<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'plan_id' => Plan::factory(),
            'service_type' => 'pppoe',
            'username' => 'user'.$this->faker->unique()->numerify('####'),
            'password' => 'secret123',
            'next_due_date' => today()->addMonth(),
            'status' => 'active',
        ];
    }
}
