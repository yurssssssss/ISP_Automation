<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_no' => 'ACC-'.$this->faker->unique()->numerify('#####'),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => '0917'.$this->faker->numerify('#######'),
        ];
    }

    public function withLogin(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => User::factory()->create(['email' => $attributes['email'], 'name' => $attributes['name']])->id,
        ]);
    }
}
