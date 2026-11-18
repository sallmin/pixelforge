<?php

namespace Database\Factories;

use App\Billing\Quality;
use App\Billing\Size;
use App\Generations\Status;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => Status::Pending,
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => Size::Square,
            'quality' => Quality::Low,
            'reference_images' => [],
            'estimate_minimum' => 0,
            'estimate_maximum' => 0,
            'provider_cost' => 0,
            'charged' => 0,
        ];
    }
}
