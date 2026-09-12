<?php

namespace Tests\Feature\Api\V1\Messaging;

use App\Enums\ConversationType;
use App\Enums\ProjectMembershipRole;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Project conversations (Phase 16) — exactly one per Project, lazily
 * created on first use (never eagerly for every Project), with
 * membership derived exclusively from Project Membership (Phase 10) and
 * never independently managed.
 */
class ProjectConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function staffWithUser(): Staff
    {
        $user = User::factory()->staff()->create();

        return Staff::factory()->create(['user_id' => $user->id]);
    }

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_project_has_no_conversation_until_first_requested(): void
    {
        $staff = $this->staffWithUser();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $staff->id]);

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_a_project_member_lazily_creates_the_conversation_on_first_use(): void
    {
        $staff = $this->staffWithUser();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $staff->id]);

        Sanctum::actingAs($staff->user);
        $response = $this->postJson("/api/v1/projects/{$project->public_id}/conversation");

        // Laravel automatically reports 201 here: the underlying model was
        // just created (wasRecentlyCreated), distinguishing this from the
        // "already existed" 200 path below.
        $response->assertCreated();
        $response->assertJsonPath('data.type', ConversationType::Project->value);
        $response->assertJsonPath('data.project.public_id', $project->public_id);
        $response->assertJsonCount(1, 'data.members');
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_requesting_it_again_returns_the_same_conversation_without_duplicating(): void
    {
        $staff = $this->staffWithUser();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $staff->id]);
        Sanctum::actingAs($staff->user);

        $first = $this->postJson("/api/v1/projects/{$project->public_id}/conversation")->assertCreated();
        $second = $this->postJson("/api/v1/projects/{$project->public_id}/conversation")->assertOk();

        $this->assertSame($first->json('data.public_id'), $second->json('data.public_id'));
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_a_non_member_cannot_access_the_project_conversation(): void
    {
        $member = $this->staffWithUser();
        $stranger = $this->staffWithUser();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $member->id]);

        Sanctum::actingAs($stranger->user);
        $this->postJson("/api/v1/projects/{$project->public_id}/conversation")->assertNotFound();
    }

    public function test_administrator_without_project_membership_cannot_access_the_project_conversation(): void
    {
        // Messaging privacy has no permission-based override — projects.view
        // (Administrator/Manager) does not by itself grant conversation
        // access; only actual current Project Membership does. The
        // Administrator here has a linked Staff record (so the "no linked
        // staff" domain check doesn't fire first) but is not a member of
        // this Project.
        $member = $this->staffWithUser();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $member->id]);

        $adminUser = User::factory()->administrator()->create();
        Staff::factory()->create(['user_id' => $adminUser->id]);
        Sanctum::actingAs($adminUser);

        $this->postJson("/api/v1/projects/{$project->public_id}/conversation")->assertNotFound();
    }

    public function test_conversation_reflects_the_full_current_roster_at_creation(): void
    {
        $lead = $this->staffWithUser();
        $member = $this->staffWithUser();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $lead->id, 'role' => ProjectMembershipRole::ProjectLead]);
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $member->id]);

        Sanctum::actingAs($lead->user);
        $this->postJson("/api/v1/projects/{$project->public_id}/conversation")
            ->assertCreated()->assertJsonCount(2, 'data.members');
    }

    public function test_adding_a_project_member_after_the_conversation_exists_syncs_membership(): void
    {
        $this->actingAsAdministrator();
        $existing = $this->staffWithUser();
        $newMember = $this->staffWithUser();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $existing->id]);

        Sanctum::actingAs($existing->user);
        $conversation = $this->postJson("/api/v1/projects/{$project->public_id}/conversation")
            ->assertCreated()->json('data.public_id');

        $this->actingAsAdministrator();
        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $newMember->public_id])
            ->assertCreated();

        Sanctum::actingAs($newMember->user);
        $this->getJson("/api/v1/conversations/{$conversation}")->assertOk();
    }

    public function test_removing_a_project_member_after_the_conversation_exists_syncs_membership(): void
    {
        $this->actingAsAdministrator();
        $staffToRemove = $this->staffWithUser();
        $remaining = $this->staffWithUser();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $staffToRemove->id]);
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $remaining->id]);

        Sanctum::actingAs($remaining->user);
        $conversation = $this->postJson("/api/v1/projects/{$project->public_id}/conversation")
            ->assertCreated()->json('data.public_id');

        $this->actingAsAdministrator();
        $this->deleteJson("/api/v1/projects/{$project->public_id}/members/{$staffToRemove->public_id}")
            ->assertNoContent();

        Sanctum::actingAs($staffToRemove->user);
        $this->getJson("/api/v1/conversations/{$conversation}")->assertNotFound();
    }

    public function test_project_membership_changes_before_the_conversation_exists_are_a_no_op(): void
    {
        $this->actingAsAdministrator();
        $staff = $this->staffWithUser();
        $project = Project::factory()->create();

        // No conversation exists yet — adding/removing membership must not
        // error even though there is nothing to sync.
        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $staff->public_id])
            ->assertCreated();
        $this->deleteJson("/api/v1/projects/{$project->public_id}/members/{$staff->public_id}")
            ->assertNoContent();

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_project_conversation_membership_cannot_be_managed_directly(): void
    {
        $staff = $this->staffWithUser();
        $other = $this->staffWithUser();
        $project = Project::factory()->create();
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $staff->id]);

        Sanctum::actingAs($staff->user);
        $conversation = $this->postJson("/api/v1/projects/{$project->public_id}/conversation")
            ->assertCreated()->json('data.public_id');

        $this->postJson("/api/v1/conversations/{$conversation}/members", ['staff_id' => $other->public_id])
            ->assertStatus(409);
        $this->deleteJson("/api/v1/conversations/{$conversation}/members/{$staff->public_id}")
            ->assertStatus(409);
    }
}
