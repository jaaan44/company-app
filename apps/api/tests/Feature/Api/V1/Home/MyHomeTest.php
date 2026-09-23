<?php

namespace Tests\Feature\Api\V1\Home;

use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Department;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Employee Home (Phase 27, DEC-052) — authentication, the response
 * contract, profile/no-profile behavior, and role-independent isolation.
 * See docs/phases/V1_PHASE_27_DEFINITION.md §6.
 */
class MyHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-09-24 08:00:00', 'UTC'));
    }

    /**
     * @return array{0: User, 1: Staff}
     */
    private function employee(string $role = 'staff'): array
    {
        $user = User::factory()->{$role}()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);

        return [$user, $staff];
    }

    /**
     * Every key at every depth — including keys whose values are arrays,
     * which array_walk_recursive() would skip.
     *
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function allKeys(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                $keys = [...$keys, ...$this->allKeys($value)];
            }
        }

        return $keys;
    }

    // --- Authentication ---------------------------------------------------

    public function test_home_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/home')->assertUnauthorized();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer 1|not-a-real-token')
            ->getJson('/api/v1/me/home')
            ->assertUnauthorized();
    }

    public function test_a_revoked_token_is_rejected(): void
    {
        [$user] = $this->employee();
        $token = $user->createToken('mobile');
        $token->accessToken->delete();

        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/me/home')
            ->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        [$user] = $this->employee();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me/home')
            ->assertUnauthorized();
    }

    public function test_an_inactive_account_gets_403_and_its_token_is_revoked(): void
    {
        $user = User::factory()->staff()->suspended()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me/home')
            ->assertForbidden()
            ->assertJson(['message' => 'This account is not currently active.']);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me/home')
            ->assertUnauthorized();
    }

    // --- Contract -----------------------------------------------------------

    public function test_the_response_has_the_exact_approved_shape(): void
    {
        [$user, $staff] = $this->employee();
        ScheduleEntry::factory()->create([
            'creator_staff_id' => $staff->id,
            'starts_at' => '2026-09-24 09:00:00',
            'ends_at' => '2026-09-24 10:00:00',
            'is_all_day' => false,
        ]);
        Task::factory()->inProgress()->create(['assignee_staff_id' => $staff->id, 'due_date' => '2026-09-24']);
        Announcement::factory()->published()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me/home')->assertOk();

        $this->assertSame(
            ['user', 'staff', 'company_day', 'today', 'tasks', 'messages', 'notifications', 'announcements'],
            array_keys($response->json('data')),
        );
        $this->assertSame(['public_id', 'name', 'role'], array_keys($response->json('data.user')));
        $this->assertSame(
            ['public_id', 'display_name', 'first_name', 'preferred_name', 'position', 'department', 'team'],
            array_keys($response->json('data.staff')),
        );
        $this->assertSame(
            ['date', 'timezone', 'starts_at', 'ends_at', 'utc_offset'],
            array_keys($response->json('data.company_day')),
        );
        $this->assertSame(['total_count', 'items'], array_keys($response->json('data.today')));
        foreach ($response->json('data.today.items') as $item) {
            $this->assertSame(
                ['source_type', 'public_id', 'title', 'activity_type', 'task_status', 'starts_at', 'ends_at', 'is_all_day'],
                array_keys($item),
            );
        }
        $this->assertSame(['open_count', 'overdue_count', 'due_today_count'], array_keys($response->json('data.tasks')));
        $this->assertSame(['unread_count'], array_keys($response->json('data.messages')));
        $this->assertSame(['unread_count'], array_keys($response->json('data.notifications')));
        $this->assertSame(['latest'], array_keys($response->json('data.announcements')));
        $this->assertSame(['public_id', 'title', 'published_at'], array_keys($response->json('data.announcements.latest.0')));

        $response->assertJson([
            'data' => [
                'user' => ['public_id' => $user->public_id, 'name' => $user->name, 'role' => 'staff'],
                'company_day' => [
                    'date' => '2026-09-24',
                    'timezone' => 'UTC',
                    'starts_at' => '2026-09-24T00:00:00+00:00',
                    'ends_at' => '2026-09-24T23:59:59+00:00',
                    'utc_offset' => '+00:00',
                ],
            ],
        ]);
    }

    public function test_no_internal_numeric_id_or_unapproved_personal_field_appears_anywhere(): void
    {
        $manager = Staff::factory()->create();
        [$user, $staff] = $this->employee();
        $staff->update([
            'manager_id' => $manager->id,
            'department_id' => Department::factory()->create()->id,
            'position_id' => Position::factory()->create()->id,
        ]);
        Task::factory()->create(['assignee_staff_id' => $staff->id, 'due_date' => '2026-09-24']);
        ScheduleEntry::factory()->create([
            'creator_staff_id' => $staff->id,
            'starts_at' => '2026-09-24 09:00:00',
            'ends_at' => '2026-09-24 10:00:00',
        ]);
        Announcement::factory()->published()->create();
        Sanctum::actingAs($user);

        $data = $this->getJson('/api/v1/me/home')->assertOk()->json('data');

        $forbiddenKeys = [
            'id', 'user_id', 'staff_id', 'role_id', 'email', 'company_email', 'company_phone',
            'employee_number', 'manager', 'manager_id', 'status', 'hire_date', 'separation_date',
            'operational_status', 'body', 'acknowledged_at', 'acknowledgements_count', 'project',
        ];
        foreach ($this->allKeys($data) as $key) {
            $this->assertNotContains($key, $forbiddenKeys, "Unexpected field '{$key}' in /me/home");
        }
        $this->assertStringNotContainsString($manager->public_id, json_encode($data));
    }

    public function test_request_parameters_can_never_change_the_subject_or_the_day(): void
    {
        [$user, $staff] = $this->employee();
        [$otherUser, $otherStaff] = $this->employee();
        Task::factory()->count(3)->create(['assignee_staff_id' => $otherStaff->id, 'due_date' => '2026-09-25']);
        Sanctum::actingAs($user);

        $plain = $this->getJson('/api/v1/me/home')->assertOk()->json('data');

        foreach ([
            "staff={$otherStaff->public_id}",
            "user={$otherUser->public_id}",
            "employee={$otherStaff->public_id}",
            'date=2026-09-25',
            'timezone=Asia/Tokyo',
        ] as $query) {
            $this->assertSame($plain, $this->getJson("/api/v1/me/home?{$query}")->assertOk()->json('data'), $query);
        }
        $this->assertSame($staff->public_id, $plain['staff']['public_id']);
        $this->assertSame(0, $plain['tasks']['open_count']);
    }

    // --- Profile ------------------------------------------------------------

    public function test_the_profile_exposes_only_the_approved_fields(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id]);
        $position = Position::factory()->create();
        [$user, $staff] = $this->employee();
        $staff->update([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'preferred_name' => 'JD',
            'department_id' => $department->id,
            'team_id' => $team->id,
            'position_id' => $position->id,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/home')
            ->assertOk()
            ->assertJsonPath('data.staff', [
                'public_id' => $staff->public_id,
                'display_name' => 'JD',
                'first_name' => 'Jane',
                'preferred_name' => 'JD',
                'position' => ['public_id' => $position->public_id, 'title' => $position->title],
                'department' => ['public_id' => $department->public_id, 'name' => $department->name],
                'team' => ['public_id' => $team->public_id, 'name' => $team->name],
            ]);
    }

    public function test_missing_position_department_and_team_are_null(): void
    {
        [$user, $staff] = $this->employee();
        $staff->update(['first_name' => 'Sam', 'last_name' => 'Lee']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/home')
            ->assertOk()
            ->assertJsonPath('data.staff.display_name', 'Sam Lee')
            ->assertJsonPath('data.staff.preferred_name', null)
            ->assertJsonPath('data.staff.position', null)
            ->assertJsonPath('data.staff.department', null)
            ->assertJsonPath('data.staff.team', null);
    }

    public function test_a_non_active_employment_status_does_not_alter_the_response(): void
    {
        [$user, $staff] = $this->employee();
        $staff->update(['status' => 'separated']);
        Task::factory()->create(['assignee_staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/home')
            ->assertOk()
            ->assertJsonPath('data.staff.public_id', $staff->public_id)
            ->assertJsonPath('data.tasks.open_count', 1);
    }

    // --- No linked Staff profile -------------------------------------------

    public function test_a_user_without_a_staff_record_gets_200_with_null_staff_dependent_sections(): void
    {
        $user = User::factory()->administrator()->create();
        Notification::factory()->forRecipient($user)->count(2)->create();
        Notification::factory()->forRecipient($user)->read()->create();
        // Other people's data exists and must never be used as a fallback.
        [, $otherStaff] = $this->employee();
        Task::factory()->create(['assignee_staff_id' => $otherStaff->id, 'due_date' => '2026-09-24']);
        Announcement::factory()->published()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/home')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'user' => ['public_id' => $user->public_id, 'name' => $user->name, 'role' => 'administrator'],
                    'staff' => null,
                    'company_day' => [
                        'date' => '2026-09-24',
                        'timezone' => 'UTC',
                        'starts_at' => '2026-09-24T00:00:00+00:00',
                        'ends_at' => '2026-09-24T23:59:59+00:00',
                        'utc_offset' => '+00:00',
                    ],
                    'today' => null,
                    'tasks' => null,
                    'messages' => null,
                    'notifications' => ['unread_count' => 2],
                    'announcements' => null,
                ],
            ]);
    }

    public function test_a_user_with_no_role_reports_a_null_role(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/home')->assertOk()->assertJsonPath('data.user.role', null);
    }

    public function test_an_employee_with_no_data_gets_zeroes_and_empty_collections(): void
    {
        [$user] = $this->employee();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/home')
            ->assertOk()
            ->assertJsonPath('data.today', ['total_count' => 0, 'items' => []])
            ->assertJsonPath('data.tasks', ['open_count' => 0, 'overdue_count' => 0, 'due_today_count' => 0])
            ->assertJsonPath('data.messages', ['unread_count' => 0])
            ->assertJsonPath('data.notifications', ['unread_count' => 0])
            ->assertJsonPath('data.announcements', ['latest' => []]);
    }

    // --- Role-independent isolation ------------------------------------------

    /**
     * Seeds a company in which $subject can *see* (through Tasks/Scheduler/
     * Dashboard visibility) plenty of other people's data, plus exactly one
     * item of each kind that is genuinely theirs, and asserts Home reports
     * only the latter.
     */
    private function assertHomeShowsOnlyOwnData(User $user, Staff $subject): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $subject->update(['department_id' => $department->id]);

        $colleague = Staff::factory()->create(['manager_id' => $subject->id, 'user_id' => User::factory()->staff()->create()->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $colleague->id]);

        // Other people's work — visible to elevated roles elsewhere, never Home.
        Task::factory()->count(2)->create(['assignee_staff_id' => $colleague->id, 'project_id' => $project->id, 'due_date' => '2026-09-24']);
        Task::factory()->create(['assignee_staff_id' => $colleague->id, 'project_id' => $project->id, 'due_date' => '2026-09-01']);
        Task::factory()->create(['assignee_staff_id' => null, 'project_id' => $project->id, 'due_date' => '2026-09-24']);
        ScheduleEntry::factory()->create([
            'creator_staff_id' => $colleague->id,
            'project_id' => $project->id,
            'starts_at' => '2026-09-24 09:00:00',
            'ends_at' => '2026-09-24 10:00:00',
        ]);
        $otherConversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $otherConversation->id, 'staff_id' => $colleague->id]);
        Message::factory()->count(4)->create(['conversation_id' => $otherConversation->id, 'sender_staff_id' => $colleague->id]);
        Notification::factory()->forRecipient($colleague->user)->count(3)->create();
        $otherDepartmentAnnouncement = Announcement::factory()->published()->scoped()->create();
        $otherDepartmentAnnouncement->departments()->attach($otherDepartment->id);
        Announcement::factory()->create(); // draft
        Announcement::factory()->archived()->create();

        // Exactly one of each thing that is genuinely the subject's own.
        Task::factory()->create(['assignee_staff_id' => $subject->id, 'due_date' => '2026-09-24', 'title' => 'Mine']);
        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $subject->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $colleague->id]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_staff_id' => $colleague->id]);
        Notification::factory()->forRecipient($user)->create();
        $ownAnnouncement = Announcement::factory()->published()->scoped()->create();
        $ownAnnouncement->departments()->attach($department->id);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me/home')->assertOk();

        $response->assertJsonPath('data.staff.public_id', $subject->public_id)
            ->assertJsonPath('data.tasks', ['open_count' => 1, 'overdue_count' => 0, 'due_today_count' => 1])
            ->assertJsonPath('data.today.total_count', 1)
            ->assertJsonPath('data.today.items.0.title', 'Mine')
            ->assertJsonPath('data.messages.unread_count', 1)
            ->assertJsonPath('data.notifications.unread_count', 1);
        $this->assertSame(
            [$ownAnnouncement->public_id],
            array_column($response->json('data.announcements.latest'), 'public_id'),
        );
    }

    public function test_an_ordinary_employee_sees_only_their_own_data(): void
    {
        [$user, $staff] = $this->employee('staff');

        $this->assertHomeShowsOnlyOwnData($user, $staff);
    }

    public function test_an_administrator_sees_only_their_own_data_never_company_wide_figures(): void
    {
        [$user, $staff] = $this->employee('administrator');

        $this->assertHomeShowsOnlyOwnData($user, $staff);
    }

    public function test_a_manager_sees_only_their_own_data_never_direct_reports_figures(): void
    {
        // The colleague seeded by the helper is the manager's direct report.
        [$user, $staff] = $this->employee('manager');

        $this->assertHomeShowsOnlyOwnData($user, $staff);
    }

    public function test_a_project_lead_sees_only_their_own_data_never_their_projects_figures(): void
    {
        [$user, $staff] = $this->employee('staff');
        $ledProject = Project::factory()->create();
        ProjectMembership::factory()->projectLead()->create(['project_id' => $ledProject->id, 'staff_id' => $staff->id]);
        $member = Staff::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $ledProject->id, 'staff_id' => $member->id]);
        Task::factory()->count(2)->create(['project_id' => $ledProject->id, 'assignee_staff_id' => $member->id, 'due_date' => '2026-09-24']);
        ScheduleEntry::factory()->create([
            'creator_staff_id' => $member->id,
            'project_id' => $ledProject->id,
            'starts_at' => '2026-09-24 11:00:00',
            'ends_at' => '2026-09-24 12:00:00',
        ]);

        $this->assertHomeShowsOnlyOwnData($user, $staff);
    }
}
