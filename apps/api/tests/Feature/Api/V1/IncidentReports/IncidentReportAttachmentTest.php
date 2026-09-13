<?php

namespace Tests\Feature\Api\V1\IncidentReports;

use App\Enums\AttachmentOwnerType;
use App\Enums\IncidentReportStatus;
use App\Models\Attachment;
use App\Models\IncidentReport;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The shared attachment infrastructure applied to Incident Reports
 * (Phase 19, DEC-042) — the second authorized consumer after Service
 * Reports (Phase 18, DEC-041): upload validation, download authorization
 * (inherits the parent report's own, narrower visibility), mutation
 * gated by both status (reported/under_investigation only) and actor
 * authority, physical file cleanup, and non-interference with existing
 * Service Report attachments. See docs/phases/V1_PHASE_19_DEFINITION.md.
 */
class IncidentReportAttachmentTest extends TestCase
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

    public function test_the_reporter_can_upload_an_attachment_to_their_own_reported_incident(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);
        $file = UploadedFile::fake()->image('scene.jpg', 10, 10)->size(200);

        $response = $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file]);

        $response->assertCreated()
            ->assertJson(['data' => ['original_filename' => 'scene.jpg', 'mime_type' => 'image/jpeg']])
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.storage_path')
            ->assertJsonMissingPath('data.storage_disk');

        $attachment = Attachment::query()->where('incident_report_id', $report->id)->firstOrFail();
        $this->assertSame(AttachmentOwnerType::IncidentReport, $attachment->owner_type);
        $this->assertNull($attachment->service_report_id);
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertStringContainsString('incident-reports/', $attachment->storage_path);
        $this->assertStringNotContainsString('scene.jpg', $attachment->storage_path);
    }

    public function test_the_assigned_investigator_can_upload_while_under_investigation(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create([
            'assigned_to_staff_id' => $investigator->id,
            'status' => IncidentReportStatus::UnderInvestigation,
        ]);
        $file = UploadedFile::fake()->image('evidence.jpg');

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file])->assertCreated();
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);
        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);
        $file = UploadedFile::fake()->image('huge.jpg')->size((int) config('attachments.max_size_kb') + 100);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_a_participant_cannot_upload_an_attachment(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create();
        $report->participants()->attach($participant->id);
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file])->assertForbidden();
    }

    public function test_an_unrelated_staff_member_gets_404_uploading_to_an_incident_they_cannot_see(): void
    {
        $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file])->assertNotFound();
    }

    public function test_attachments_cannot_be_added_to_a_resolved_incident(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->resolved()->create();
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file])->assertStatus(409);
    }

    public function test_attachments_cannot_be_added_to_a_closed_incident(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->closed()->create();
        $file = UploadedFile::fake()->image('photo.jpg');

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file])->assertStatus(409);
    }

    public function test_reopening_restores_attachment_upload_capability(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->resolved()->create(['assigned_to_staff_id' => $investigator->id]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/reopen")->assertOk();

        $file = UploadedFile::fake()->image('follow-up.jpg');
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file])->assertCreated();
    }

    // --- Download -------------------------------------------------------

    public function test_the_reporter_can_download_their_own_attachment(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);
        $attachment = $this->attachTo($report);

        $this->get("/api/v1/incident-reports/{$report->public_id}/attachments/{$attachment->public_id}/download")
            ->assertOk();
    }

    public function test_the_assigned_investigator_can_download_an_attachment(): void
    {
        [, $investigator] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['assigned_to_staff_id' => $investigator->id]);
        $attachment = $this->attachTo($report);

        $this->get("/api/v1/incident-reports/{$report->public_id}/attachments/{$attachment->public_id}/download")
            ->assertOk();
    }

    public function test_a_participant_can_download_an_attachment(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create();
        $report->participants()->attach($participant->id);
        $attachment = $this->attachTo($report);

        $this->get("/api/v1/incident-reports/{$report->public_id}/attachments/{$attachment->public_id}/download")
            ->assertOk();
    }

    public function test_an_unrelated_staff_member_cannot_download_an_attachment(): void
    {
        $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create();
        $attachment = $this->attachTo($report);

        $this->get("/api/v1/incident-reports/{$report->public_id}/attachments/{$attachment->public_id}/download")
            ->assertNotFound();
    }

    public function test_a_project_lead_cannot_download_an_attachment_merely_from_the_project_link(): void
    {
        $leadUser = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $leadUser->id]);
        $report = IncidentReport::factory()->create();
        $attachment = $this->attachTo($report);

        Sanctum::actingAs($leadUser);
        $this->get("/api/v1/incident-reports/{$report->public_id}/attachments/{$attachment->public_id}/download")
            ->assertNotFound();
    }

    public function test_an_attachment_addressed_through_the_wrong_incident_report_is_not_found(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();
        $otherReport = IncidentReport::factory()->create();
        $attachment = $this->attachTo($report);

        $this->get("/api/v1/incident-reports/{$otherReport->public_id}/attachments/{$attachment->public_id}/download")
            ->assertNotFound();
    }

    // --- Removal --------------------------------------------------------

    public function test_the_reporter_can_remove_an_attachment_from_their_own_reported_incident_and_the_file_is_deleted(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);
        $attachment = $this->attachTo($report);

        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}/attachments/{$attachment->public_id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->storage_path);
    }

    public function test_a_participant_cannot_remove_an_attachment(): void
    {
        [, $participant] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create();
        $report->participants()->attach($participant->id);
        $attachment = $this->attachTo($report);

        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}/attachments/{$attachment->public_id}")
            ->assertForbidden();
    }

    public function test_an_attachment_cannot_be_removed_from_a_resolved_incident(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();
        $attachment = $this->attachTo($report);
        $report->update(['status' => IncidentReportStatus::Resolved, 'resolution' => 'Done', 'corrective_action' => 'Fixed']);

        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}/attachments/{$attachment->public_id}")
            ->assertStatus(409);
    }

    // --- Cleanup on report deletion -----------------------------------------

    public function test_deleting_a_reported_unassigned_incident_deletes_its_attachment_files(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $report = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id]);
        $attachment = $this->attachTo($report);

        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}")->assertNoContent();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->storage_path);
    }

    // --- Cross-module compatibility ------------------------------------------

    public function test_a_service_report_attachment_is_unaffected_by_the_incident_report_extension(): void
    {
        $this->actingAsAdministrator();
        $serviceReport = ServiceReport::factory()->create();
        $attachment = Attachment::factory()->create([
            'service_report_id' => $serviceReport->id,
            'original_filename' => 'service-evidence.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        Storage::disk('local')->put($attachment->storage_path, 'fake-file-contents');

        $this->assertSame(AttachmentOwnerType::ServiceReport, $attachment->fresh()->owner_type);
        $this->assertNull($attachment->fresh()->incident_report_id);

        $this->get("/api/v1/service-reports/{$serviceReport->public_id}/attachments/{$attachment->public_id}/download")
            ->assertOk();
    }

    public function test_incident_report_and_service_report_attachments_coexist_independently(): void
    {
        $this->actingAsAdministrator();
        $serviceReport = ServiceReport::factory()->create();
        $serviceAttachment = Attachment::factory()->create(['service_report_id' => $serviceReport->id]);

        $incidentReport = IncidentReport::factory()->create();
        $incidentAttachment = Attachment::factory()->forIncidentReport()->create(['incident_report_id' => $incidentReport->id]);

        $this->assertDatabaseHas('attachments', [
            'id' => $serviceAttachment->id, 'owner_type' => 'service_report', 'incident_report_id' => null,
        ]);
        $this->assertDatabaseHas('attachments', [
            'id' => $incidentAttachment->id, 'owner_type' => 'incident_report', 'service_report_id' => null,
        ]);
    }

    /**
     * Creates an Attachment row plus a real file on the faked disk at
     * its exact storage_path — used by tests that only need an existing
     * attachment to exercise download/removal authorization, not the
     * upload flow itself (already covered separately above).
     */
    private function attachTo(IncidentReport $report): Attachment
    {
        $attachment = Attachment::factory()->forIncidentReport()->create([
            'incident_report_id' => $report->id,
            'original_filename' => 'evidence.jpg',
            'storage_disk' => 'local',
            'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('local')->put($attachment->storage_path, 'fake-file-contents');

        return $attachment;
    }
}
