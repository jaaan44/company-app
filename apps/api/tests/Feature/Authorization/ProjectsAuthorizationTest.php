<?php

namespace Tests\Feature\Authorization;

use App\Enums\AccountStatus;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP-level authorization coverage for the Phase 10 Projects & Project
 * Membership endpoints (CLAUDE.md §18): `projects.view` (Manager-only,
 * company-wide) and `projects.manage` (Administrator-only) gate the
 * permission-based paths; a Staff member with no `projects.view` is
 * instead scoped to Projects where they hold a Project Membership —
 * enforced in-controller, not by route middleware alone (the second real
 * row-level visibility pattern after Phase 9's CheckInController). See
 * docs/phases/V1_PHASE_10_DEFINITION.md.
 */
class ProjectsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_view_and_manage_every_project(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects')->assertOk();
        $this->getJson("/api/v1/projects/{$project->public_id}")->assertOk();
        $this->postJson('/api/v1/projects', ['name' => 'New Project'])->assertCreated();
        $this->putJson("/api/v1/projects/{$project->public_id}", ['name' => 'Renamed'])->assertOk();
        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertNoContent();
    }

    public function test_manager_can_view_every_project_but_not_manage_them(): void
    {
        $manager = User::factory()->manager()->create();
        Sanctum::actingAs($manager);
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/projects/{$project->public_id}")->assertOk();
        $this->postJson('/api/v1/projects', ['name' => 'New Project'])->assertForbidden();
        $this->putJson("/api/v1/projects/{$project->public_id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertForbidden();
    }

    public function test_a_staff_member_of_a_project_can_view_only_that_project(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $myProject = Project::factory()->create();
        ProjectMembership::factory()->for($myProject, 'project')->for($staff, 'staff')->create();
        $otherProject = Project::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $myProject->public_id]]]);

        $this->getJson("/api/v1/projects/{$myProject->public_id}")->assertOk();
        $this->getJson("/api/v1/projects/{$myProject->public_id}/members")->assertOk();

        $this->getJson("/api/v1/projects/{$otherProject->public_id}")->assertForbidden();
        $this->getJson("/api/v1/projects/{$otherProject->public_id}/members")->assertForbidden();
    }

    public function test_a_staff_member_cannot_manage_projects_or_membership(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/projects', ['name' => 'New Project'])->assertForbidden();
        $this->putJson("/api/v1/projects/{$project->public_id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertForbidden();

        $otherStaff = Staff::factory()->create();
        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $otherStaff->public_id])
            ->assertForbidden();
    }

    public function test_a_staff_member_with_no_project_memberships_sees_no_projects(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Project::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_user_with_no_role_and_no_linked_staff_cannot_view_any_projects(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Project::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/projects')->assertForbidden();
    }

    public function test_user_with_no_role_but_a_linked_staff_record_sees_only_their_own_memberships(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $myProject = Project::factory()->create();
        ProjectMembership::factory()->for($myProject, 'project')->for($staff, 'staff')->create();
        Project::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $myProject->public_id]]]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->getJson("/api/v1/projects/{$project->public_id}")->assertUnauthorized();
        $this->postJson('/api/v1/projects', ['name' => 'New Project'])->assertUnauthorized();
    }

    public function test_suspended_account_loses_projects_access_mid_session(): void
    {
        $user = User::factory()->administrator()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/projects')
            ->assertForbidden();
    }
}
