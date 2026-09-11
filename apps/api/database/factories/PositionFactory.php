<?php

namespace Database\Factories;

use App\Enums\OrganizationStatus;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'department_id' => null,
            'title' => fake()->unique()->jobTitle(),
            'description' => fake()->optional()->sentence(),
            'status' => OrganizationStatus::Active,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrganizationStatus::Inactive,
        ]);
    }
}
