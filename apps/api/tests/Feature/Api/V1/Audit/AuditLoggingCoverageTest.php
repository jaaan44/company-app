<?php

namespace Tests\Feature\Api\V1\Audit;

use App\Enums\AuditSource;
use App\Models\Announcement;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Department;
use App\Models\IncidentReport;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Project;
use App\Models\ScheduleEntry;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkLog;
use App\Support\Audit\AuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end coverage of the Phase 21 (DEC-009/DEC-044) audit event
 * catalog: every authorized event actually produces exactly one
 * `audit_logs` row with the expected action/entity, and every explicitly
 * excluded, high-volume/self-service action produces none.
 */
class AuditLoggingCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function staffLinkedAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    // --- Authentication ---------------------------------------------------

    public function test_successful_api_login_is_audited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::AUTH_LOGIN_SUCCEEDED,
            'actor_user_id' => $user->id,
            'entity_public_id' => $user->public_id,
            'source' => AuditSource::Api->value,
        ]);
    }

    public function test_failed_api_login_is_audited_without_a_password_or_actor(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable();

        $log = AuditLog::query()->where('action', AuditActions::AUTH_LOGIN_FAILED)->firstOrFail();

        $this->assertNull($log->actor_user_id);
        $this->assertSame($user->public_id, $log->entity_public_id);
        $this->assertStringNotContainsString('wrong-password', (string) json_encode($log->getAttributes()));
    }

    public function test_failed_login_against_an_unknown_email_is_audited_with_no_entity(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::AUTH_LOGIN_FAILED,
            'actor_user_id' => null,
            'entity_public_id' => null,
        ]);
    }

    public function test_api_logout_is_audited(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::AUTH_LOGOUT,
            'actor_user_id' => $user->id,
            'source' => AuditSource::Api->value,
        ]);
    }

    // --- Staff --------------------------------------------------------------

    public function test_staff_creation_is_audited(): void
    {
        $admin = $this->actingAsAdministrator();

        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-9001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ])->assertCreated();

        $staff = Staff::query()->where('employee_number', 'EMP-9001')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::STAFF_CREATED,
            'actor_user_id' => $admin->id,
            'entity_type' => 'Staff',
            'entity_public_id' => $staff->public_id,
        ]);
    }

    public function test_staff_status_update_is_audited_but_ordinary_profile_edits_are_not(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->inactive()->create();

        // An ordinary profile-only edit (name) produces no audit entry.
        $this->putJson("/api/v1/staff/{$staff->public_id}", ['first_name' => 'Renamed'])->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'Staff', 'entity_public_id' => $staff->public_id]);

        // A status change does.
        $this->putJson("/api/v1/staff/{$staff->public_id}", ['status' => 'active'])->assertOk();

        $log = AuditLog::query()->where('entity_type', 'Staff')->where('entity_public_id', $staff->public_id)->firstOrFail();
        $this->assertSame(AuditActions::STAFF_UPDATED, $log->action);
        $this->assertSame(['status'], $log->changed_fields);
    }

    public function test_staff_separation_is_audited_as_a_distinct_event(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();

        $this->putJson("/api/v1/staff/{$staff->public_id}", [
            'status' => 'separated',
            'separation_date' => now()->toDateString(),
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::STAFF_SEPARATED,
            'entity_type' => 'Staff',
            'entity_public_id' => $staff->public_id,
        ]);
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditActions::STAFF_UPDATED]);
    }

    // --- Organization Structure ----------------------------------------------

    public function test_department_create_update_delete_are_audited(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/departments', ['name' => 'Engineering'])->assertCreated();
        $department = Department::query()->where('name', 'Engineering')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::DEPARTMENT_CREATED, 'entity_public_id' => $department->public_id,
        ]);

        $this->putJson("/api/v1/departments/{$department->public_id}", ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::DEPARTMENT_UPDATED, 'entity_public_id' => $department->public_id,
        ]);

        $this->deleteJson("/api/v1/departments/{$department->public_id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::DEPARTMENT_DELETED, 'entity_public_id' => $department->public_id,
        ]);
    }

    public function test_team_create_update_delete_are_audited(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/teams', ['name' => 'Platform'])->assertCreated();
        $team = Team::query()->where('name', 'Platform')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::TEAM_CREATED, 'entity_public_id' => $team->public_id]);

        $this->putJson("/api/v1/teams/{$team->public_id}", ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::TEAM_UPDATED, 'entity_public_id' => $team->public_id]);

        $this->deleteJson("/api/v1/teams/{$team->public_id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::TEAM_DELETED, 'entity_public_id' => $team->public_id]);
    }

    public function test_position_create_update_delete_are_audited(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/positions', ['title' => 'Staff Engineer'])->assertCreated();
        $position = Position::query()->where('title', 'Staff Engineer')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::POSITION_CREATED, 'entity_public_id' => $position->public_id]);

        $this->putJson("/api/v1/positions/{$position->public_id}", ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::POSITION_UPDATED, 'entity_public_id' => $position->public_id]);

        $this->deleteJson("/api/v1/positions/{$position->public_id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::POSITION_DELETED, 'entity_public_id' => $position->public_id]);
    }

    // --- Clients & Contacts ---------------------------------------------------

    public function test_client_create_update_delete_are_audited(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/clients', ['name' => 'Acme Corp'])->assertCreated();
        $client = Client::query()->where('name', 'Acme Corp')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::CLIENT_CREATED, 'entity_public_id' => $client->public_id]);

        $this->putJson("/api/v1/clients/{$client->public_id}", ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::CLIENT_UPDATED, 'entity_public_id' => $client->public_id]);

        $this->deleteJson("/api/v1/clients/{$client->public_id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::CLIENT_DELETED, 'entity_public_id' => $client->public_id]);
    }

    public function test_contact_create_update_delete_are_audited(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create();

        $this->postJson('/api/v1/contacts', [
            'client_id' => $client->public_id, 'first_name' => 'Jane', 'last_name' => 'Doe',
        ])->assertCreated();
        $contact = Contact::query()->where('first_name', 'Jane')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::CONTACT_CREATED, 'entity_public_id' => $contact->public_id]);

        $this->putJson("/api/v1/contacts/{$contact->public_id}", ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::CONTACT_UPDATED, 'entity_public_id' => $contact->public_id]);

        $this->deleteJson("/api/v1/contacts/{$contact->public_id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::CONTACT_DELETED, 'entity_public_id' => $contact->public_id]);
    }

    // --- Projects & Project Membership ----------------------------------------

    public function test_project_create_update_delete_are_audited(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/projects', ['name' => 'New Portal'])->assertCreated();
        $project = Project::query()->where('name', 'New Portal')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::PROJECT_CREATED, 'entity_public_id' => $project->public_id]);

        $this->putJson("/api/v1/projects/{$project->public_id}", ['status' => 'active'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::PROJECT_UPDATED, 'entity_public_id' => $project->public_id]);

        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::PROJECT_DELETED, 'entity_public_id' => $project->public_id]);
    }

    public function test_project_membership_add_role_change_and_remove_are_audited(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        $staff = Staff::factory()->create();

        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $staff->public_id])
            ->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::PROJECT_MEMBERSHIP_ADDED]);

        $this->putJson("/api/v1/projects/{$project->public_id}/members/{$staff->public_id}", ['role' => 'project_lead'])
            ->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::PROJECT_MEMBERSHIP_ROLE_CHANGED]);

        $this->deleteJson("/api/v1/projects/{$project->public_id}/members/{$staff->public_id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::PROJECT_MEMBERSHIP_REMOVED]);
    }

    // --- Tasks (deletion only) ------------------------------------------------

    public function test_task_deletion_is_audited(): void
    {
        $this->actingAsAdministrator();
        $task = Task::factory()->create();

        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertNoContent();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::TASK_DELETED, 'entity_public_id' => $task->public_id]);
    }

    public function test_ordinary_task_create_and_update_are_not_audited(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/tasks', ['title' => 'Do the thing'])->assertCreated();
        $task = Task::query()->where('title', 'Do the thing')->firstOrFail();

        $this->putJson("/api/v1/tasks/{$task->public_id}", ['status' => 'in_progress'])->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'Task', 'entity_public_id' => $task->public_id]);
    }

    // --- Announcements --------------------------------------------------------

    public function test_announcement_publish_and_archive_are_audited(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->create();

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::ANNOUNCEMENT_PUBLISHED, 'entity_public_id' => $announcement->public_id,
        ]);

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/archive")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::ANNOUNCEMENT_ARCHIVED, 'entity_public_id' => $announcement->public_id,
        ]);
    }

    public function test_announcement_acknowledgement_is_not_audited(): void
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $announcement = Announcement::factory()->published()->create();

        $this->postJson("/api/v1/me/announcements/{$announcement->public_id}/acknowledge")->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'Announcement', 'entity_public_id' => $announcement->public_id]);
    }

    // --- Attachments (Service Reports & Incident Reports) ----------------------

    public function test_service_report_attachment_upload_and_delete_are_audited_but_download_is_not(): void
    {
        Storage::fake('local');
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->create();

        $file = UploadedFile::fake()->image('photo.jpg', 10, 10)->size(100);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/attachments", ['file' => $file])->assertCreated();

        $attachment = Attachment::query()->where('service_report_id', $report->id)->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::ATTACHMENT_UPLOADED, 'entity_public_id' => $attachment->public_id,
        ]);

        $this->get("/api/v1/service-reports/{$report->public_id}/attachments/{$attachment->public_id}/download")->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'attachment.downloaded']);

        $this->deleteJson("/api/v1/service-reports/{$report->public_id}/attachments/{$attachment->public_id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::ATTACHMENT_DELETED, 'entity_public_id' => $attachment->public_id,
        ]);
    }

    public function test_incident_report_attachment_upload_and_delete_are_audited(): void
    {
        Storage::fake('local');
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->create();

        $file = UploadedFile::fake()->image('photo.jpg', 10, 10)->size(100);
        $this->postJson("/api/v1/incident-reports/{$report->public_id}/attachments", ['file' => $file])->assertCreated();

        $attachment = Attachment::query()->where('incident_report_id', $report->id)->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::ATTACHMENT_UPLOADED,
            'entity_public_id' => $attachment->public_id,
        ]);
        $log = AuditLog::query()->where('entity_public_id', $attachment->public_id)->where('action', AuditActions::ATTACHMENT_UPLOADED)->firstOrFail();
        $this->assertSame('incident_report', $log->after['owner_type']);

        $this->deleteJson("/api/v1/incident-reports/{$report->public_id}/attachments/{$attachment->public_id}")->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditActions::ATTACHMENT_DELETED, 'entity_public_id' => $attachment->public_id]);
    }

    // --- Phase 20 Report exports + the Audit Log's own export ------------------

    public function test_every_phase_20_report_export_is_audited(): void
    {
        $this->actingAsAdministrator();

        $reports = ['staff', 'work-logs', 'leave-requests', 'projects', 'tasks', 'service-reports', 'incident-reports'];

        foreach ($reports as $report) {
            $this->get("/api/v1/reports/{$report}/export")->assertOk();

            $this->assertDatabaseHas('audit_logs', [
                'action' => AuditActions::REPORT_EXPORTED,
            ]);
        }

        $this->assertSame(
            count($reports),
            AuditLog::query()->where('action', AuditActions::REPORT_EXPORTED)->count(),
        );
    }

    // --- Excluded, high-volume/self-service actions -----------------------------

    public function test_work_log_mutation_is_not_audited(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $project = Project::factory()->create();

        $this->postJson('/api/v1/work-logs', [
            'staff_id' => $staff->public_id,
            'project_id' => $project->public_id,
            'work_date' => now()->toDateString(),
            'duration_minutes' => 60,
            'description' => 'Did some work',
        ])->assertCreated();

        $this->assertSame(1, WorkLog::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'WorkLog']);
    }

    public function test_schedule_entry_mutation_is_not_audited(): void
    {
        $user = $this->staffLinkedAdministrator();

        $this->postJson('/api/v1/schedule-entries', [
            'title' => 'Standup',
            'activity_type' => 'meeting',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
        ])->assertCreated();

        $this->assertSame(1, ScheduleEntry::query()->count());
        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'ScheduleEntry']);
    }

    public function test_message_send_is_not_audited(): void
    {
        $userA = User::factory()->create();
        $staffA = Staff::factory()->create(['user_id' => $userA->id]);
        $staffB = Staff::factory()->create();
        Sanctum::actingAs($userA);

        $response = $this->postJson('/api/v1/conversations/direct', ['staff_id' => $staffB->public_id])->assertCreated();
        $conversationPublicId = $response->json('data.public_id');

        $this->postJson("/api/v1/conversations/{$conversationPublicId}/messages", ['body' => 'Hello there'])
            ->assertCreated();

        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'Message']);
        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'Conversation']);
    }

    public function test_check_in_is_not_audited(): void
    {
        $user = User::factory()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/check-ins', ['latitude' => 14.5, 'longitude' => 121.0])->assertCreated();

        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'StaffCheckIn']);
    }

    public function test_leave_request_workflow_transition_is_not_audited(): void
    {
        $this->actingAsAdministrator();
        $leaveRequest = LeaveRequest::factory()->create();

        $this->postJson("/api/v1/leave-requests/{$leaveRequest->public_id}/approve")->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'LeaveRequest']);
        // The domain history table remains the authoritative record.
        $this->assertDatabaseHas('leave_request_actions', ['leave_request_id' => $leaveRequest->id, 'action' => 'approved']);
    }

    public function test_service_report_workflow_transition_is_not_audited(): void
    {
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->create();

        $this->postJson("/api/v1/service-reports/{$report->public_id}/submit")->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'ServiceReport']);
        $this->assertDatabaseHas('service_report_actions', ['service_report_id' => $report->id, 'action' => 'submitted']);
    }

    public function test_incident_report_workflow_transition_is_not_audited(): void
    {
        $this->actingAsAdministrator();
        $report = IncidentReport::factory()->underInvestigation()->create([
            'resolution' => 'Fixed the issue.',
            'corrective_action' => 'Replaced the part.',
        ]);

        $this->postJson("/api/v1/incident-reports/{$report->public_id}/resolve")->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['entity_type' => 'IncidentReport']);
        $this->assertDatabaseHas('incident_report_actions', ['incident_report_id' => $report->id, 'action' => 'resolved']);
    }

    public function test_ordinary_get_requests_are_never_audited(): void
    {
        $this->actingAsAdministrator();
        Staff::factory()->count(3)->create();

        $this->getJson('/api/v1/staff')->assertOk();
        $this->getJson('/api/v1/dashboard')->assertOk();
        $this->getJson('/api/v1/reports/staff')->assertOk();

        $this->assertSame(0, AuditLog::query()->count());
    }
}
