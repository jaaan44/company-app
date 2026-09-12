<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationMember>
 */
class ConversationMemberFactory extends Factory
{
    protected $model = ConversationMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'staff_id' => Staff::factory(),
            'last_read_message_id' => null,
        ];
    }
}
