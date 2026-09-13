<?php

namespace Database\Factories;

use App\Enums\AttachmentOwnerType;
use App\Models\Attachment;
use App\Models\IncidentReport;
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
            'incident_report_id' => null,
            'original_filename' => fake()->word().'.jpg',
            'storage_disk' => 'local',
            'storage_path' => "service-reports/test/{$ulid}.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(1000, 500000),
            'uploaded_by_user_id' => null,
        ];
    }

    /**
     * The second AttachmentOwnerType (Phase 19, DEC-042) — an Incident
     * Report-owned attachment row, with service_report_id null.
     */
    public function forIncidentReport(): static
    {
        $ulid = (string) Str::ulid();

        return $this->state(fn (array $attributes) => [
            'owner_type' => AttachmentOwnerType::IncidentReport,
            'service_report_id' => null,
            'incident_report_id' => IncidentReport::factory(),
            'storage_path' => "incident-reports/test/{$ulid}.jpg",
        ]);
    }
}
