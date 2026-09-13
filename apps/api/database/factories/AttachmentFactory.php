<?php

namespace Database\Factories;

use App\Enums\AttachmentOwnerType;
use App\Models\Attachment;
use App\Models\ServiceReport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ulid = (string) Str::ulid();

        return [
            'owner_type' => AttachmentOwnerType::ServiceReport,
            'service_report_id' => ServiceReport::factory(),
            'original_filename' => fake()->word().'.jpg',
            'storage_disk' => 'local',
            'storage_path' => "service-reports/test/{$ulid}.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(1000, 500000),
            'uploaded_by_user_id' => null,
        ];
    }
}
