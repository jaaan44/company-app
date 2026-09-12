<?php

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recipient_user_id' => User::factory(),
            'type' => NotificationType::AnnouncementPublished,
            'title' => fake()->sentence(4),
            'message' => 'A new company announcement has been published.',
            'source_type' => null,
            'source_public_id' => null,
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }

    public function forRecipient(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'recipient_user_id' => $user->id,
        ]);
    }
}
