<?php

namespace Tests\Feature\Authorization;

use App\Models\Announcement;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 35A (DEC-065): the five former `.manage` permissions are now
 * separate add / edit / delete permissions. Each test gives a role exactly
 * one of them and checks it opens its own endpoints and no others — so a
 * role can later be given "add tasks" without "delete tasks". The default
 * grants (Administrator everything, Manager/Staff none of these) are
 * covered by each module's own *AuthorizationTest, unchanged.
 */
class GranularPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingWith(array $permissions): User
    {
        $role = Role::query()->create(['name' => 'tester-'.implode('-', $permissions), 'label' => 'Tester']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_task_add_edit_and_delete_are_separate(): void
    {
        $task = Task::factory()->create();

        $this->actingWith(['tasks.create']);
        $this->postJson('/api/v1/tasks', ['title' => 'New'])->assertCreated();
        $this->putJson("/api/v1/tasks/{$task->public_id}", ['title' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertForbidden();

        $this->actingWith(['tasks.update']);
        $this->postJson('/api/v1/tasks', ['title' => 'New'])->assertForbidden();
        $this->putJson("/api/v1/tasks/{$task->public_id}", ['title' => 'Renamed'])->assertOk();
        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertForbidden();

        $this->actingWith(['tasks.delete']);
        $this->postJson('/api/v1/tasks', ['title' => 'New'])->assertForbidden();
        $this->putJson("/api/v1/tasks/{$task->public_id}", ['title' => 'Again'])->assertForbidden();
        $this->deleteJson("/api/v1/tasks/{$task->public_id}")->assertNoContent();
    }

    public function test_project_add_edit_delete_and_members_are_separate(): void
    {
        $project = Project::factory()->create();
        $member = Staff::factory()->create();

        $this->actingWith(['projects.create']);
        $this->postJson('/api/v1/projects', ['name' => 'New'])->assertCreated();
        $this->putJson("/api/v1/projects/{$project->public_id}", ['name' => 'Renamed'])->assertForbidden();
        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $member->public_id])->assertForbidden();
        $this->postJson("/api/v1/projects/{$project->public_id}/milestones", ['title' => 'M1', 'due_date' => '2026-12-01'])->assertForbidden();

        $this->actingWith(['projects.update']);
        $this->postJson('/api/v1/projects', ['name' => 'New'])->assertForbidden();
        $this->putJson("/api/v1/projects/{$project->public_id}", ['name' => 'Renamed'])->assertOk();
        $this->postJson("/api/v1/projects/{$project->public_id}/milestones", ['title' => 'M1', 'due_date' => '2026-12-01'])->assertCreated();
        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $member->public_id])->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->public_id}")->assertForbidden();

        $this->actingWith(['projects.members']);
        $this->postJson("/api/v1/projects/{$project->public_id}/members", ['staff_id' => $member->public_id])->assertCreated();
        $this->deleteJson("/api/v1/projects/{$project->public_id}/members/{$member->public_id}")->assertNoContent();
        $this->putJson("/api/v1/projects/{$project->public_id}", ['name' => 'Again'])->assertForbidden();

        $empty = Project::factory()->create();
        $this->actingWith(['projects.delete']);
        $this->putJson("/api/v1/projects/{$empty->public_id}", ['name' => 'Again'])->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$empty->public_id}")->assertNoContent();
    }

    public function test_client_add_edit_and_delete_are_separate_and_contacts_follow_edit(): void
    {
        $client = Client::factory()->create();
        $contact = ['client_id' => $client->public_id, 'first_name' => 'Jane', 'last_name' => 'Doe'];

        $this->actingWith(['clients.create']);
        $this->postJson('/api/v1/clients', ['name' => 'Acme'])->assertCreated();
        $this->putJson("/api/v1/clients/{$client->public_id}", ['name' => 'Renamed'])->assertForbidden();
        $this->postJson('/api/v1/contacts', $contact)->assertForbidden();

        $this->actingWith(['clients.update']);
        $this->postJson('/api/v1/clients', ['name' => 'Acme 2'])->assertForbidden();
        $this->putJson("/api/v1/clients/{$client->public_id}", ['name' => 'Renamed'])->assertOk();
        $created = $this->postJson('/api/v1/contacts', $contact)->assertCreated();
        $this->deleteJson('/api/v1/contacts/'.$created->json('data.public_id'))->assertNoContent();
        $this->deleteJson("/api/v1/clients/{$client->public_id}")->assertForbidden();

        $this->actingWith(['clients.delete']);
        $this->putJson("/api/v1/clients/{$client->public_id}", ['name' => 'Again'])->assertForbidden();
        $this->deleteJson("/api/v1/clients/{$client->public_id}")->assertNoContent();
    }

    public function test_staff_add_edit_and_delete_are_separate_and_edit_shows_the_account(): void
    {
        $person = Staff::factory()->create(['user_id' => User::factory()->create()->id]);
        $plain = Staff::factory()->create();

        $this->actingWith(['staff.view', 'staff.create']);
        $this->postJson('/api/v1/staff', ['employee_number' => 'EMP-9001', 'first_name' => 'Ada', 'last_name' => 'Lovelace'])->assertCreated();
        $this->putJson("/api/v1/staff/{$person->public_id}", ['first_name' => 'Renamed'])->assertForbidden();
        $this->getJson("/api/v1/staff/{$person->public_id}")->assertOk()->assertJsonMissingPath('data.user');

        $this->actingWith(['staff.view', 'staff.update']);
        $this->postJson('/api/v1/staff', ['employee_number' => 'EMP-9002', 'first_name' => 'Ada', 'last_name' => 'King'])->assertForbidden();
        $this->putJson("/api/v1/staff/{$person->public_id}", ['first_name' => 'Renamed'])->assertOk();
        $this->getJson("/api/v1/staff/{$person->public_id}")->assertOk()->assertJsonPath('data.user.public_id', $person->user->public_id);
        $this->deleteJson("/api/v1/staff/{$plain->public_id}")->assertForbidden();

        $this->actingWith(['staff.delete']);
        $this->putJson("/api/v1/staff/{$plain->public_id}", ['first_name' => 'Again'])->assertForbidden();
        $this->deleteJson("/api/v1/staff/{$plain->public_id}")->assertNoContent();
    }

    public function test_announcement_view_all_draft_publish_and_delete_are_separate(): void
    {
        $draft = Announcement::factory()->create();

        $this->actingWith(['announcements.view-all']);
        $this->getJson('/api/v1/announcements')->assertOk();
        $this->getJson("/api/v1/announcements/{$draft->public_id}")->assertOk()->assertJsonPath('data.public_id', $draft->public_id);
        $this->postJson('/api/v1/announcements', ['title' => 'X', 'body' => 'Y'])->assertForbidden();

        $this->actingWith(['announcements.create']);
        $this->getJson('/api/v1/announcements')->assertForbidden();
        $this->postJson('/api/v1/announcements', ['title' => 'X', 'body' => 'Y'])->assertCreated();
        $this->putJson("/api/v1/announcements/{$draft->public_id}", ['title' => 'Edited'])->assertOk();
        $this->postJson("/api/v1/announcements/{$draft->public_id}/publish")->assertForbidden();
        $this->deleteJson("/api/v1/announcements/{$draft->public_id}")->assertForbidden();

        $this->actingWith(['announcements.publish']);
        $this->putJson("/api/v1/announcements/{$draft->public_id}", ['title' => 'Again'])->assertForbidden();
        $this->postJson("/api/v1/announcements/{$draft->public_id}/publish")->assertOk();
        $this->postJson("/api/v1/announcements/{$draft->public_id}/archive")->assertOk();

        $other = Announcement::factory()->create();
        $this->actingWith(['announcements.delete']);
        $this->postJson("/api/v1/announcements/{$other->public_id}/publish")->assertForbidden();
        $this->deleteJson("/api/v1/announcements/{$other->public_id}")->assertNoContent();
    }

    public function test_the_old_manage_permissions_no_longer_exist(): void
    {
        foreach (['staff.manage', 'clients.manage', 'projects.manage', 'tasks.manage', 'announcements.manage'] as $old) {
            $this->assertFalse(Permission::query()->where('name', $old)->exists(), $old);
        }
    }

    private function splitMigration(): Migration
    {
        return require database_path('migrations/2026_10_13_000000_split_manage_permissions.php');
    }

    public function test_the_migration_moves_an_existing_grant_onto_every_part_and_back(): void
    {
        // Rebuild the pre-35A state for tasks: tasks.manage granted to Manager.
        Permission::query()->whereIn('name', ['tasks.create', 'tasks.update', 'tasks.delete'])->delete();
        $manage = Permission::query()->create(['name' => 'tasks.manage', 'label' => 'Create, update, and delete any task']);
        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();
        $staff = Role::query()->where('name', Role::STAFF)->firstOrFail();
        $manager->permissions()->attach($manage->id);
        $before = $manager->permissions()->count();

        $this->splitMigration()->up();

        $this->assertFalse(Permission::query()->where('name', 'tasks.manage')->exists());
        $names = $manager->permissions()->pluck('name');
        foreach (['tasks.create', 'tasks.update', 'tasks.delete'] as $name) {
            $this->assertTrue($names->contains($name), $name);
            $this->assertFalse($staff->permissions()->where('name', $name)->exists(), $name);
        }
        $this->assertSame($before + 2, $manager->permissions()->count());

        $this->splitMigration()->down();

        $this->assertTrue($manager->permissions()->where('name', 'tasks.manage')->exists());
        $this->assertFalse(Permission::query()->where('name', 'tasks.create')->exists());
        $this->assertFalse($staff->permissions()->where('name', 'tasks.manage')->exists());
        $this->assertSame($before, $manager->permissions()->count());
    }

    public function test_rolling_back_gives_the_old_permission_only_to_a_role_holding_every_part(): void
    {
        $manager = Role::query()->where('name', Role::MANAGER)->firstOrFail();
        $manager->permissions()->attach(Permission::query()->where('name', 'tasks.create')->value('id'));

        $this->splitMigration()->down();

        $this->assertTrue(Permission::query()->where('name', 'tasks.manage')->exists());
        $this->assertFalse($manager->permissions()->where('name', 'tasks.manage')->exists());
        $this->assertFalse(Permission::query()->where('name', 'tasks.create')->exists());
    }

    public function test_the_migration_does_nothing_on_a_fresh_catalog(): void
    {
        $before = Permission::query()->orderBy('name')->pluck('name')->all();

        $this->splitMigration()->up();

        $this->assertSame($before, Permission::query()->orderBy('name')->pluck('name')->all());
    }
}
