<?php

namespace Database\Factories;

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'status' => AnnouncementStatus::Draft,
            'audience_type' => AnnouncementAudienceType::CompanyWide,
            'published_at' => null,
            'created_by_user_id' => null,
            'published_by_user_id' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AnnouncementStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AnnouncementStatus::Archived,
            'published_at' => now()->subDay(),
        ]);
    }

    public function scoped(): static
    {
        return $this->state(fn (array $attributes) => [
            'audience_type' => AnnouncementAudienceType::Scoped,
        ]);
    }
}
