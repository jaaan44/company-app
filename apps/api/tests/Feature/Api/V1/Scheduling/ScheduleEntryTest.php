<?php

namespace Tests\Feature\Api\V1\Scheduling;

use App\Enums\ScheduleEntryActivityType;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleEntryTest extends TestCase
{
    use RefreshDatabase;

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

    // --- CRUD -----------------------------------------------------------

    public function test_a_staff_linked_user_can_create_a_timed_schedule_entry(): void
    {
        [, $staff] = $this->actingAsStaffMember();

        $response = $this->postJson('/api/v1/schedule-entries', [
            'title' => 'Kickoff meeting',
            'description' => 'Discuss project scope.',
            'activity_type' => 'meeting',
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T10:00:00Z',
        ]);

        $response->assertCreated()
            ->assertJson([
                'data' => [
                    'title' => 'Kickoff meeting',
                    'activity_type' => 'meeting',
                    'is_all_day' => false,
                    'starts_at' => '2026-10-01T09:00:00+00:00',
                    'ends_at' => '2026-10-01T10:00:00+00:00',
                    'creator' => ['public_id' => $staff->public_id],
                ],
            ])
            ->assertJsonMissingPath('data.id');

        $this->assertDatabaseHas('schedule_entries', ['title' => 'Kickoff meeting', 'creator_staff_id' => $staff->id]);
    }

    public function test_a_user_with_no_linked_staff_record_cannot_create_a_schedule_entry(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/v1/schedule-entries', [
            'title' => 'X',
            'activity_type' => 'meeting',
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T10:00:00Z',
        ])->assertForbidden();
    }

    public function test_creating_a_schedule_entry_requires_a_title_and_activity_type(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/schedule-entries', [
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T10:00:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'activity_type']);
    }

    #[DataProvider('activityTypeProvider')]
    public function test_each_activity_type_is_accepted(string $activityType): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/schedule-entries', [
            'title' => 'X',
            'activity_type' => $activityType,
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T10:00:00Z',
        ])->assertCreated()->assertJson(['data' => ['activity_type' => $activityType]]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function activityTypeProvider(): array
    {
        return array_combine(
            array_map(fn (ScheduleEntryActivityType $case) => $case->value, ScheduleEntryActivityType::cases()),
            array_map(fn (ScheduleEntryActivityType $case) => [$case->value], ScheduleEntryActivityType::cases()),
        );
    }

    public function test_an_invalid_activity_type_is_rejected(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/schedule-entries', [
            'title' => 'X',
            'activity_type' => 'not-a-type',
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T10:00:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors('activity_type');
    }

    public function test_the_creator_can_view_update_and_delete_their_own_entry(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $entry = ScheduleEntry::factory()->create(['creator_staff_id' => $staff->id, 'title' => 'Old']);

        $this->getJson("/api/v1/schedule-entries/{$entry->public_id}")->assertOk();

        $this->putJson("/api/v1/schedule-entries/{$entry->public_id}", ['title' => 'New'])
            ->assertOk()->assertJson(['data' => ['title' => 'New']]);

        $this->deleteJson("/api/v1/schedule-entries/{$entry->public_id}")->assertNoContent();
        $this->assertDatabaseMissing('schedule_entries', ['id' => $entry->id]);
    }

    public function test_administrator_can_manage_any_schedule_entry(): void
    {
        $this->actingAsAdministrator();
        $entry = ScheduleEntry::factory()->create(['title' => 'Someone else\'s entry']);

        $this->getJson("/api/v1/schedule-entries/{$entry->public_id}")->assertOk();
        $this->putJson("/api/v1/schedule-entries/{$entry->public_id}", ['title' => 'Corrected'])->assertOk();
        $this->deleteJson("/api/v1/schedule-entries/{$entry->public_id}")->assertNoContent();
    }

    public function test_viewing_a_schedule_entry_by_internal_numeric_id_fails(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $entry = ScheduleEntry::factory()->create(['creator_staff_id' => $staff->id]);

        $this->getJson("/api/v1/schedule-entries/{$entry->id}")->assertNotFound();
    }

    public function test_project_id_cannot_be_changed_on_update(): void
    {
        $this->actingAsAdministrator();
        $entry = ScheduleEntry::factory()->create();
        $otherProject = Project::factory()->create();

        $this->putJson("/api/v1/schedule-entries/{$entry->public_id}", ['project_id' => $otherProject->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    // --- Privacy / row-level authorization -------------------------------

    public function test_an_unrelated_staff_member_gets_404_for_a_private_entry(): void
    {
        [, $viewer] = $this->actingAsStaffMember();
        $entry = ScheduleEntry::factory()->create();

        $this->getJson("/api/v1/schedule-entries/{$entry->public_id}")->assertNotFound();
        $this->putJson("/api/v1/schedule-entries/{$entry->public_id}", ['title' => 'X'])->assertNotFound();
        $this->deleteJson("/api/v1/schedule-entries/{$entry->public_id}")->assertNotFound();
    }

    public function test_a_participant_can_view_but_not_edit_or_delete_an_entry(): void
    {
        [, $participantStaff] = $this->actingAsStaffMember();
        $entry = ScheduleEntry::factory()->create();
        $entry->participants()->attach($participantStaff->id);

        $this->getJson("/api/v1/schedule-entries/{$entry->public_id}")->assertOk();
        $this->putJson("/api/v1/schedule-entries/{$entry->public_id}", ['title' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/schedule-entries/{$entry->public_id}")->assertForbidden();
    }

    public function test_a_manager_does_not_automatically_see_a_direct_reports_private_entry(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $managerUser = User::factory()->manager()->create();
        $managerStaff = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $managerStaff->id]);
        $entry = ScheduleEntry::factory()->create(['creator_staff_id' => $report->id]);

        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/schedule-entries/{$entry->public_id}")->assertNotFound();
    }

    public function test_a_project_lead_can_manage_entries_linked_to_their_project(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $leadUser = User::factory()->staff()->create();
        $leadStaff = Staff::factory()->create(['user_id' => $leadUser->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($leadStaff, 'staff')->projectLead()->create();

        $otherCreator = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($otherCreator, 'staff')->create();
        $entry = ScheduleEntry::factory()->create(['creator_staff_id' => $otherCreator->id, 'project_id' => $project->id]);

        Sanctum::actingAs($leadUser);

        $this->getJson("/api/v1/schedule-entries/{$entry->public_id}")->assertOk();
        $this->putJson("/api/v1/schedule-entries/{$entry->public_id}", ['title' => 'Updated by lead'])
            ->assertOk()->assertJson(['data' => ['title' => 'Updated by lead']]);
        $this->deleteJson("/api/v1/schedule-entries/{$entry->public_id}")->assertNoContent();
    }

    public function test_a_project_member_who_is_not_lead_can_view_but_not_manage_a_project_linked_entry(): void
    {
        $project = Project::factory()->create();
        [, $memberStaff] = $this->actingAsStaffMember();
        ProjectMembership::factory()->for($project, 'project')->for($memberStaff, 'staff')->create();

        $otherCreator = Staff::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($otherCreator, 'staff')->create();
        $entry = ScheduleEntry::factory()->create(['creator_staff_id' => $otherCreator->id, 'project_id' => $project->id]);

        $this->getJson("/api/v1/schedule-entries/{$entry->public_id}")->assertOk();
        $this->putJson("/api/v1/schedule-entries/{$entry->public_id}", ['title' => 'X'])->assertForbidden();
    }

    public function test_a_manager_holding_projects_view_sees_project_linked_entries_without_membership(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        $project = Project::factory()->create();
        $entry = ScheduleEntry::factory()->create(['project_id' => $project->id]);

        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/schedule-entries/{$entry->public_id}")->assertOk();
    }

    // --- Participants -----------------------------------------------------

    public function test_participants_can_be_set_on_creation(): void
    {
        [, $creator] = $this->actingAsStaffMember();
        $participant = Staff::factory()->create();

        $response = $this->postJson('/api/v1/schedule-entries', [
            'title' => 'Team sync',
            'activity_type' => 'meeting',
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T10:00:00Z',
            'participant_staff_ids' => [$participant->public_id],
        ]);

        $response->assertCreated()->assertJson([
            'data' => ['participants' => [['public_id' => $participant->public_id]]],
        ]);

        $entryId = ScheduleEntry::query()->where('creator_staff_id', $creator->id)->value('id');
        $this->assertDatabaseHas('schedule_entry_participants', ['schedule_entry_id' => $entryId, 'staff_id' => $participant->id]);
    }

    public function test_the_creator_is_never_duplicated_into_the_participant_list(): void
    {
        [, $creator] = $this->actingAsStaffMember();

        $response = $this->postJson('/api/v1/schedule-entries', [
            'title' => 'Solo block',
            'activity_type' => 'other',
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T10:00:00Z',
            'participant_staff_ids' => [$creator->public_id],
        ]);

        $response->assertCreated();
        $entryId = ScheduleEntry::query()->where('creator_staff_id', $creator->id)->value('id');
        $this->assertDatabaseMissing('schedule_entry_participants', ['schedule_entry_id' => $entryId, 'staff_id' => $creator->id]);
    }

    public function test_participants_can_be_replaced_on_update(): void
    {
        [, $creator] = $this->actingAsStaffMember();
        $entry = ScheduleEntry::factory()->create(['creator_staff_id' => $creator->id]);
        $originalParticipant = Staff::factory()->create();
        $entry->participants()->attach($originalParticipant->id);
        $newParticipant = Staff::factory()->create();

        $this->putJson("/api/v1/schedule-entries/{$entry->public_id}", [
            'participant_staff_ids' => [$newParticipant->public_id],
        ])->assertOk()->assertJson([
            'data' => ['participants' => [['public_id' => $newParticipant->public_id]]],
        ]);

        $this->assertDatabaseMissing('schedule_entry_participants', ['schedule_entry_id' => $entry->id, 'staff_id' => $originalParticipant->id]);
        $this->assertDatabaseHas('schedule_entry_participants', ['schedule_entry_id' => $entry->id, 'staff_id' => $newParticipant->id]);
    }

    public function test_a_participant_appears_on_the_participants_own_schedule_entry_list(): void
    {
        [, $participantStaff] = $this->actingAsStaffMember();
        $entry = ScheduleEntry::factory()->create(['title' => 'Shared meeting']);
        $entry->participants()->attach($participantStaff->id);

        $this->getJson('/api/v1/schedule-entries')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'Shared meeting']]]);
    }

    // --- Timing / validation ----------------------------------------------

    public function test_a_timed_entry_is_stored_and_returned_as_utc_iso8601(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/schedule-entries', [
            'title' => 'X',
            'activity_type' => 'meeting',
            'starts_at' => '2026-10-01T09:00:00+02:00',
            'ends_at' => '2026-10-01T10:00:00+02:00',
        ])->assertCreated()->assertJson([
            'data' => ['starts_at' => '2026-10-01T07:00:00+00:00', 'ends_at' => '2026-10-01T08:00:00+00:00'],
        ]);
    }

    public function test_an_all_day_entry_is_accepted_with_plain_dates(): void
    {
        $this->actingAsStaffMember();

        $response = $this->postJson('/api/v1/schedule-entries', [
            'title' => 'Company picnic',
            'activity_type' => 'company_event',
            'is_all_day' => true,
            'starts_at' => '2026-10-05',
            'ends_at' => '2026-10-05',
        ])->assertCreated()->assertJson(['data' => ['is_all_day' => true]]);

        $this->assertStringStartsWith('2026-10-05', $response->json('data.starts_at'));
    }

    public function test_an_all_day_entry_rejects_a_full_datetime_string(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/schedule-entries', [
            'title' => 'X',
            'activity_type' => 'other',
            'is_all_day' => true,
            'starts_at' => '2026-10-05T09:00:00Z',
            'ends_at' => '2026-10-05T09:00:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
    }

    public function test_an_end_before_the_start_is_rejected_for_a_timed_entry(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/schedule-entries', [
            'title' => 'X',
            'activity_type' => 'meeting',
            'starts_at' => '2026-10-01T10:00:00Z',
            'ends_at' => '2026-10-01T09:00:00Z',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_an_end_date_before_the_start_date_is_rejected_for_an_all_day_entry(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/schedule-entries', [
            'title' => 'X',
            'activity_type' => 'other',
            'is_all_day' => true,
            'starts_at' => '2026-10-05',
            'ends_at' => '2026-10-04',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_equal_start_and_end_are_accepted(): void
    {
        $this->actingAsStaffMember();

        $this->postJson('/api/v1/schedule-entries', [
            'title' => 'Point in time',
            'activity_type' => 'other',
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T09:00:00Z',
        ])->assertCreated();
    }

    public function test_a_partial_update_preserves_all_day_semantics_for_the_unchanged_field(): void
    {
        [, $creator] = $this->actingAsStaffMember();
        $entry = ScheduleEntry::factory()->allDay()->create(['creator_staff_id' => $creator->id, 'starts_at' => '2026-10-05 00:00:00', 'ends_at' => '2026-10-05 23:59:59']);

        $this->putJson("/api/v1/schedule-entries/{$entry->public_id}", ['ends_at' => '2026-10-04'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    // --- Filters ------------------------------------------------------------

    public function test_entries_can_be_filtered_by_activity_type(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        ScheduleEntry::factory()->create(['creator_staff_id' => $staff->id, 'activity_type' => ScheduleEntryActivityType::Meeting, 'title' => 'A meeting']);
        ScheduleEntry::factory()->create(['creator_staff_id' => $staff->id, 'activity_type' => ScheduleEntryActivityType::Training, 'title' => 'A training']);

        $this->getJson('/api/v1/schedule-entries?activity_type=training')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'A training']]]);
    }

    public function test_entries_can_be_filtered_by_project(): void
    {
        [, $staff] = $this->actingAsStaffMember();
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        ScheduleEntry::factory()->create(['creator_staff_id' => $staff->id, 'project_id' => $project->id, 'title' => 'Linked']);
        ScheduleEntry::factory()->create(['creator_staff_id' => $staff->id, 'title' => 'Unlinked']);

        $this->getJson("/api/v1/schedule-entries?project={$project->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['title' => 'Linked']]]);
    }

    // --- Relational integrity ------------------------------------------------

    public function test_a_staff_member_who_created_a_schedule_entry_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        ScheduleEntry::factory()->create(['creator_staff_id' => $staff->id]);

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertStatus(409);
    }

    public function test_a_staff_member_who_is_a_participant_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        $entry = ScheduleEntry::factory()->create();
        $entry->participants()->attach($staff->id);

        $this->deleteJson("/api/v1/staff/{$staff->public_id}")->assertStatus(409);
    }

    public function test_a_project_with_schedule_entries_cannot_be_deleted(): void
    {
        $this->actingAsAdministrator();
        $project = Project::factory()->create();
        ScheduleEntry::factory()->create(['project_id' => $project->id]);

        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertStatus(409);
    }

    // --- Account state ---------------------------------------------------

    public function test_a_suspended_account_cannot_access_schedule_entries(): void
    {
        $user = User::factory()->staff()->suspended()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/schedule-entries')->assertForbidden();
    }
}
