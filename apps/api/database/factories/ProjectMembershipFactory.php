<?php

namespace Database\Factories;

use App\Enums\ProjectMembershipRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMembership>
 */
class ProjectMembershipFactory extends Factory
{
    protected $model = ProjectMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'staff_id' => Staff::factory(),
            'role' => ProjectMembershipRole::Member,
        ];
    }

    public function projectLead(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ProjectMembershipRole::ProjectLead,
        ]);
    }
}
