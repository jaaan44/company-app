<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\StaffCheckIn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffCheckIn>
 */
class StaffCheckInFactory extends Factory
{
    protected $model = StaffCheckIn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'accuracy_meters' => fake()->numberBetween(3, 50),
            'location_label' => fake()->randomElement(['Office', 'Client Site', 'Remote', 'Warehouse', 'Field']),
            'note' => null,
            'status' => null,
        ];
    }
}
