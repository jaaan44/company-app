<?php

namespace Database\Factories;

use App\Enums\OperationalStatus;
use App\Models\Staff;
use App\Models\StaffOperationalStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffOperationalStatus>
 */
class StaffOperationalStatusFactory extends Factory
{
    protected $model = StaffOperationalStatus::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'status' => fake()->randomElement(OperationalStatus::cases()),
            'changed_by_user_id' => null,
        ];
    }
}
