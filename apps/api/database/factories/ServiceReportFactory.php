<?php

namespace Database\Factories;

use App\Enums\ServiceReportStatus;
use App\Models\Client;
use App\Models\ServiceReport;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceReport>
 */
class ServiceReportFactory extends Factory
{
    protected $model = ServiceReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'project_id' => null,
            'task_id' => null,
            'creator_staff_id' => Staff::factory(),
            'created_by_user_id' => null,
            'service_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'work_performed' => fake()->paragraph(),
            'findings' => fake()->optional()->sentence(),
            'recommendations' => fake()->optional()->sentence(),
            'follow_up_actions' => fake()->optional()->sentence(),
            'site_representative_name' => fake()->optional()->name(),
            'status' => ServiceReportStatus::Draft,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServiceReportStatus::Submitted,
        ]);
    }

    public function reviewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServiceReportStatus::Reviewed,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ServiceReportStatus::Rejected,
        ]);
    }
}
