<?php

namespace Database\Factories;

use App\Models\TrafficViolation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficViolation>
 */
class TrafficViolationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'citation_number' => fake()->optional()->bothify('CIT-####'),
            'violation_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'violation_time' => fake()->optional()->time('H:i'),
            'location' => fake()->optional()->streetAddress(),
            'issuing_agency' => fake()->randomElement(['LTO', 'LGU Traffic Office', 'MMDA', 'PNP', 'Other']),
            'violation_type' => fake()->randomElement(array_keys(TrafficViolation::TYPE_LABELS)),
            'description' => fake()->optional()->sentence(),
            'responsibility' => 'under_review', 'status' => 'reported', 'payment_status' => 'not_applicable',
            'reported_by' => User::factory(),
        ];
    }
}
