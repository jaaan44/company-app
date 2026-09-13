<?php

namespace Database\Factories;

use App\Enums\ProjectMilestoneStatus;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMilestone>
 */
class ProjectMilestoneFactory extends Factory
{
    protected $model = ProjectMilestone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->unique()->sentence(3),
            'due_date' => fake()->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'status' => ProjectMilestoneStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectMilestoneStatus::Completed,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectMilestoneStatus::Cancelled,
        ]);
    }
}
