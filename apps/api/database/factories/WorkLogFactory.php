<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Staff;
use App\Models\WorkLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkLog>
 */
class WorkLogFactory extends Factory
{
    protected $model = WorkLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'task_id' => null,
            'project_id' => Project::factory(),
            'created_by_user_id' => null,
            'work_date' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'duration_minutes' => fake()->numberBetween(15, 480),
            'description' => fake()->sentence(),
        ];
    }
}
