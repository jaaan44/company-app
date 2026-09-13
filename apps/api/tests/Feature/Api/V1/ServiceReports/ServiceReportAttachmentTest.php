<?php

namespace Tests\Feature\Api\V1\ServiceReports;

use App\Enums\ServiceReportStatus;
use App\Models\Attachment;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The shared attachment infrastructure applied to Service Reports (Phase
 * 18, DEC-041): upload validation, download authorization (inherits the
 * parent report's own visibility), draft-only mutation, and physical
 * file cleanup. See docs/phases/V1_PHASE_18_DEFINITION.md.
 */
class ServiceReportAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array{0: User, 1: Staff}
     */
    private function actingAsStaffMember(): array
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return [$user, $staff];
    }

    // --- Upload -------------------------------------------------------------

    public function test_the_creator_can_upload_an_attachment_to_their_own_draft_report(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);
        $file = UploadedFile::fake()->image('before.jpg', 10, 10)->size(200);

        $response = $this->postJson("/api/v1/service-reports/{$report->public_id}/attachments", ['file' => $file]);

        $response->assertCreated()
            ->assertJson(['data' => ['original_filename' => 'before.jpg', 'mime_type' => 'image/jpeg']])
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.storage_path')
            ->assertJsonMissingPath('data.storage_disk');

        $attachment = Attachment::query()->where('service_report_id', $report->id)->firstOrFail();
        Storage::disk('local')->assertExists($attachment->storage_path);
        // The stored filename is never the original — never trusted as a
        // path (docs/phases/V1_PHASE_18_DEFINITION.md's Attachment
        // Metadata/Security).
        $this->assertStringNotContainsString('before.jpg', $attachment->storage_path);
    }

    public function test_a_pdf_attachment_is_accepted(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);
        $file = UploadedFile::fake()->create('signed-form.pdf', 500, 'application/pdf');

        $this->postJson("/api/v1/service-reports/{$report->public_id}/attachments", ['file' => $file])
            ->assertCreated();
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);
        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');

        $this->postJson("/api/v1/service-reports/{$report->public_id}/attachments", ['file' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);
        $file = UploadedFile::fake()->image('huge.jpg')->size((int) config('attachments.max_size_kb') + 100);

        $this->postJson("/api/v1/service-reports/{$report->public_id}/attachments", ['file' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_a_participant_cannot_upload_an_attachment(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create();
        $report->participants()->attach($participant->id);
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson("/api/v1/service-reports/{$report->public_id}/attachments", ['file' => $file])->assertForbidden();
    }

    public function test_an_unrelated_staff_member_gets_404_uploading_to_a_report_they_cannot_see(): void
    {
        $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson("/api/v1/service-reports/{$report->public_id}/attachments", ['file' => $file])->assertNotFound();
    }

    public function test_attachments_cannot_be_added_to_a_submitted_report(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->submitted()->create();
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson("/api/v1/service-reports/{$report->public_id}/attachments", ['file' => $file])->assertStatus(409);
    }

    // --- Download -------------------------------------------------------

    public function test_the_creator_can_download_their_own_attachment(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);
        $attachment = $this->attachTo($report);

        $this->get("/api/v1/service-reports/{$report->public_id}/attachments/{$attachment->public_id}/download")
            ->assertOk();
    }

    public function test_a_participant_can_download_an_attachment(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create();
        $report->participants()->attach($participant->id);
        $attachment = $this->attachTo($report);

        $this->get("/api/v1/service-reports/{$report->public_id}/attachments/{$attachment->public_id}/download")
            ->assertOk();
    }

    public function test_an_unrelated_staff_member_cannot_download_an_attachment(): void
    {
        $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create();
        $attachment = $this->attachTo($report);

        $this->get("/api/v1/service-reports/{$report->public_id}/attachments/{$attachment->public_id}/download")
            ->assertNotFound();
    }

    public function test_an_attachment_addressed_through_the_wrong_report_is_not_found(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->create();
        $otherReport = ServiceReport::factory()->create();
        $attachment = $this->attachTo($report);

        $this->get("/api/v1/service-reports/{$otherReport->public_id}/attachments/{$attachment->public_id}/download")
            ->assertNotFound();
    }

    // --- Removal --------------------------------------------------------

    public function test_the_creator_can_remove_an_attachment_from_their_own_draft_and_the_file_is_deleted(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);
        $attachment = $this->attachTo($report);

        $this->deleteJson("/api/v1/service-reports/{$report->public_id}/attachments/{$attachment->public_id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->storage_path);
    }

    public function test_a_participant_cannot_remove_an_attachment(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create();
        $report->participants()->attach($participant->id);
        $attachment = $this->attachTo($report);

        $this->deleteJson("/api/v1/service-reports/{$report->public_id}/attachments/{$attachment->public_id}")
            ->assertForbidden();
    }

    public function test_an_attachment_cannot_be_removed_from_a_submitted_report(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->create();
        $attachment = $this->attachTo($report);
        $report->update(['status' => ServiceReportStatus::Submitted]);

        $this->deleteJson("/api/v1/service-reports/{$report->public_id}/attachments/{$attachment->public_id}")
            ->assertStatus(409);
    }

    // --- Cleanup on report deletion -----------------------------------------

    public function test_deleting_a_draft_report_deletes_its_attachment_files(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id]);
        $attachment = $this->attachTo($report);

        $this->deleteJson("/api/v1/service-reports/{$report->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->storage_path);
    }

    /**
     * Creates an Attachment row plus a real file on the faked disk at
     * its exact storage_path — used by tests that only need an existing
     * attachment to exercise download/removal authorization, not the
     * upload flow itself (already covered separately above).
     */
    private function attachTo(ServiceReport $report): Attachment
    {
        $attachment = Attachment::factory()->create([
            'service_report_id' => $report->id,
            'original_filename' => 'evidence.jpg',
            'storage_disk' => 'local',
            'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('local')->put($attachment->storage_path, 'fake-file-contents');

        return $attachment;
    }
}
