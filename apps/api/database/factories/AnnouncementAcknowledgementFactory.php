<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\AnnouncementAcknowledgement;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnnouncementAcknowledgement>
 */
class AnnouncementAcknowledgementFactory extends Factory
{
    protected $model = AnnouncementAcknowledgement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'announcement_id' => Announcement::factory()->published(),
            'staff_id' => Staff::factory(),
        ];
    }
}
