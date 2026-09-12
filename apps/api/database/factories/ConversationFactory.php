<?php

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ConversationType::Direct,
            'name' => null,
            'project_id' => null,
            'owner_staff_id' => null,
        ];
    }

    public function group(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ConversationType::Group,
            'name' => fake()->words(3, true),
        ]);
    }

    public function project(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ConversationType::Project,
            'name' => null,
        ]);
    }
}
