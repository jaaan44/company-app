<?php

namespace Database\Factories;

use App\Enums\IncidentReportStatus;
use App\Enums\IncidentReportType;
use App\Enums\IncidentSeverity;
use App\Models\IncidentReport;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentReport>
 */
class IncidentReportFactory extends Factory
{
    protected $model = IncidentReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => null,
            'project_id' => null,
            'task_id' => null,
            'reporter_staff_id' => Staff::factory(),
            'assigned_to_staff_id' => null,
            'created_by_user_id' => null,
            'occurred_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'location' => fake()->optional()->streetAddress(),
            'incident_type' => IncidentReportType::Other,
            'severity' => IncidentSeverity::Medium,
            'description' => fake()->paragraph(),
            'immediate_action_taken' => fake()->optional()->sentence(),
            'root_cause' => null,
            'corrective_action' => null,
            'preventive_action' => null,
            'follow_up_actions' => null,
            'resolution' => null,
            'people_involved' => fake()->optional()->sentence(),
            'witness_notes' => null,
            'status' => IncidentReportStatus::Reported,
        ];
    }

    public function assigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to_staff_id' => Staff::factory(),
        ]);
    }

    public function underInvestigation(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to_staff_id' => Staff::factory(),
            'status' => IncidentReportStatus::UnderInvestigation,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to_staff_id' => Staff::factory(),
            'status' => IncidentReportStatus::Resolved,
            'resolution' => fake()->sentence(),
            'corrective_action' => fake()->sentence(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to_staff_id' => Staff::factory(),
            'status' => IncidentReportStatus::Closed,
            'resolution' => fake()->sentence(),
            'corrective_action' => fake()->sentence(),
        ]);
    }
}
