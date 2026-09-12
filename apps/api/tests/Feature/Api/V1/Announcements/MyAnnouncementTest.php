<?php

namespace Tests\Feature\Api\V1\Announcements;

use App\Models\Announcement;
use App\Models\Department;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsStaff(?Department $department = null, ?Team $team = null): Staff
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create([
            'user_id' => $user->id,
            'department_id' => $department?->id,
            'team_id' => $team?->id,
        ]);
        Sanctum::actingAs($user);

        return $staff;
    }

    // --- Visibility -----------------------------------------------------------

    public function test_a_company_wide_announcement_is_visible_to_any_staff_member(): void
    {
        $this->actingAsStaff();
        Announcement::factory()->published()->create();

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_draft_announcement_is_never_visible(): void
    {
        $this->actingAsStaff();
        Announcement::factory()->create();

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_archived_announcement_is_never_visible(): void
    {
        $this->actingAsStaff();
        Announcement::factory()->archived()->create();

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_department_scoped_announcement_is_visible_to_a_member_of_that_department(): void
    {
        $department = Department::factory()->create();
        $this->actingAsStaff($department);
        $announcement = Announcement::factory()->published()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_department_scoped_announcement_is_hidden_from_an_unrelated_department(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $this->actingAsStaff($otherDepartment);
        $announcement = Announcement::factory()->published()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_team_scoped_announcement_is_visible_to_a_member_of_that_team(): void
    {
        $team = Team::factory()->create();
        $this->actingAsStaff(null, $team);
        $announcement = Announcement::factory()->published()->scoped()->create();
        $announcement->teams()->attach($team->id);

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_union_semantics_across_department_and_team_targets(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->create();
        $unrelatedTeam = Team::factory()->create();
        $this->actingAsStaff($department, $unrelatedTeam);

        $announcement = Announcement::factory()->published()->scoped()->create();
        $announcement->departments()->attach($department->id);
        $announcement->teams()->attach($team->id);

        // Visible via the Department match even though the Team doesn't
        // match — union (OR), not intersection.
        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_staff_member_with_no_department_or_team_only_sees_company_wide_announcements(): void
    {
        $this->actingAsStaff();
        Announcement::factory()->published()->create();
        $department = Department::factory()->create();
        $scoped = Announcement::factory()->published()->scoped()->create();
        $scoped->departments()->attach($department->id);

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_moving_into_a_targeted_department_makes_a_published_announcement_newly_visible(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        $announcement = Announcement::factory()->published()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(0, 'data');

        $staff->update(['department_id' => $department->id]);

        // Re-authenticate with a freshly-fetched User: Sanctum::actingAs()
        // reuses the same in-memory User object across every request in
        // this test, and Eloquent caches its `staff` relation after the
        // first access above — a test-harness artifact only (a real HTTP
        // request always resolves the User fresh from the database).
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_moving_out_of_a_targeted_department_removes_visibility(): void
    {
        $department = Department::factory()->create();
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id, 'department_id' => $department->id]);
        Sanctum::actingAs($user);
        $announcement = Announcement::factory()->published()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(1, 'data');

        $staff->update(['department_id' => null]);

        // See the identical note in the "moving into" test above.
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_manager_status_grants_no_extra_visibility_beyond_own_department_or_team(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $user = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $user->id, 'department_id' => $otherDepartment->id]);
        Sanctum::actingAs($user);

        $announcement = Announcement::factory()->published()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(0, 'data');
    }

    // --- Detail / 404 privacy --------------------------------------------------

    public function test_a_visible_announcement_can_be_shown_by_public_id(): void
    {
        $this->actingAsStaff();
        $announcement = Announcement::factory()->published()->create();

        $this->getJson("/api/v1/me/announcements/{$announcement->public_id}")
            ->assertOk()->assertJson(['data' => ['public_id' => $announcement->public_id]]);
    }

    public function test_an_announcement_outside_the_audience_returns_404_not_403(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $this->actingAsStaff($otherDepartment);
        $announcement = Announcement::factory()->published()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->getJson("/api/v1/me/announcements/{$announcement->public_id}")->assertNotFound();
    }

    public function test_a_draft_announcement_detail_returns_404(): void
    {
        $this->actingAsStaff();
        $announcement = Announcement::factory()->create();

        $this->getJson("/api/v1/me/announcements/{$announcement->public_id}")->assertNotFound();
    }

    // --- Acknowledgement ---------------------------------------------------------

    public function test_a_staff_member_can_acknowledge_a_visible_announcement(): void
    {
        $this->actingAsStaff();
        $announcement = Announcement::factory()->published()->create();

        $response = $this->postJson("/api/v1/me/announcements/{$announcement->public_id}/acknowledge");

        $response->assertOk();
        $response->assertJsonPath('data.acknowledged_at', fn ($value) => $value !== null);
        $this->assertDatabaseCount('announcement_acknowledgements', 1);
    }

    public function test_acknowledging_is_idempotent(): void
    {
        $this->actingAsStaff();
        $announcement = Announcement::factory()->published()->create();

        $first = $this->postJson("/api/v1/me/announcements/{$announcement->public_id}/acknowledge")->json('data.acknowledged_at');
        $second = $this->postJson("/api/v1/me/announcements/{$announcement->public_id}/acknowledge")->json('data.acknowledged_at');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('announcement_acknowledgements', 1);
    }

    public function test_cannot_acknowledge_an_announcement_outside_the_audience(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $this->actingAsStaff($otherDepartment);
        $announcement = Announcement::factory()->published()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->postJson("/api/v1/me/announcements/{$announcement->public_id}/acknowledge")->assertNotFound();
    }

    public function test_cannot_acknowledge_a_draft_announcement(): void
    {
        $this->actingAsStaff();
        $announcement = Announcement::factory()->create();

        $this->postJson("/api/v1/me/announcements/{$announcement->public_id}/acknowledge")->assertNotFound();
    }

    public function test_a_coworkers_acknowledgement_state_is_not_exposed(): void
    {
        $announcement = Announcement::factory()->published()->create();
        $coworker = Staff::factory()->create();
        $announcement->acknowledgements()->create(['staff_id' => $coworker->id]);

        $this->actingAsStaff();

        $this->getJson("/api/v1/me/announcements/{$announcement->public_id}")
            ->assertOk()->assertJson(['data' => ['acknowledged_at' => null]]);
    }

    // --- Ordinary privacy --------------------------------------------------------

    public function test_a_no_role_user_with_linked_staff_can_still_use_self_service(): void
    {
        $user = User::factory()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        Announcement::factory()->published()->create();

        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_user_with_no_linked_staff_record_cannot_use_self_service(): void
    {
        $user = User::factory()->staff()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/announcements')->assertForbidden();
    }
}
