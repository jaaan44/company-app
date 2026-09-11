<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_code' => 'PRJ-'.fake()->unique()->numerify('#####'),
            'name' => fake()->unique()->catchPhrase(),
            'description' => fake()->optional()->sentence(),
            'client_id' => null,
            'status' => ProjectStatus::Planned,
            'start_date' => null,
            'target_end_date' => null,
            'completed_date' => null,
            'notes' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Active,
        ]);
    }

    public function onHold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::OnHold,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Completed,
            'completed_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProjectStatus::Cancelled,
        ]);
    }
}
