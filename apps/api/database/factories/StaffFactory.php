<?php

namespace Database\Factories;

use App\Enums\StaffStatus;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_number' => 'EMP-'.fake()->unique()->numerify('#####'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'preferred_name' => null,
            'company_email' => fake()->unique()->safeEmail(),
            'company_phone' => fake()->phoneNumber(),
            'status' => StaffStatus::Active,
            'hire_date' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'separation_date' => null,
            'department_id' => null,
            'team_id' => null,
            'position_id' => null,
            'manager_id' => null,
            'user_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StaffStatus::Inactive,
        ]);
    }

    public function separated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StaffStatus::Separated,
            'separation_date' => fake()->dateTimeBetween($attributes['hire_date'] ?? '-1 year', 'now')->format('Y-m-d'),
        ]);
    }
}
