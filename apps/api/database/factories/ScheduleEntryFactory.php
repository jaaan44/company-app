<?php

namespace Database\Factories;

use App\Enums\ScheduleEntryActivityType;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleEntry>
 */
class ScheduleEntryFactory extends Factory
{
    protected $model = ScheduleEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+1 month');
        $end = (clone $start)->modify('+1 hour');

        return [
            'creator_staff_id' => Staff::factory(),
            'title' => fake()->unique()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'activity_type' => ScheduleEntryActivityType::Meeting,
            'starts_at' => $start,
            'ends_at' => $end,
            'is_all_day' => false,
            'project_id' => null,
        ];
    }

    public function allDay(): static
    {
        return $this->state(function (array $attributes) {
            $day = fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d');

            return [
                'starts_at' => $day.' 00:00:00',
                'ends_at' => $day.' 23:59:59',
                'is_all_day' => true,
            ];
        });
    }

    public function activityType(ScheduleEntryActivityType $type): static
    {
        return $this->state(fn (array $attributes) => ['activity_type' => $type]);
    }
}
