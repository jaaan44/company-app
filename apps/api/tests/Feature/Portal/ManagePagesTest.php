<?php

namespace Tests\Feature\Portal;

use App\Enums\ProjectMembershipRole;
use App\Livewire\Portal\AnnouncementForm;
use App\Livewire\Portal\AnnouncementManage;
use App\Livewire\Portal\AnnouncementsManage;
use App\Livewire\Portal\ClientForm;
use App\Livewire\Portal\ClientShow;
use App\Livewire\Portal\ContactForm;
use App\Livewire\Portal\ProjectForm;
use App\Livewire\Portal\Projects;
use App\Livewire\Portal\ProjectShow;
use App\Livewire\Portal\TaskForm;
use App\Livewire\Portal\Tasks;
use App\Livewire\Portal\TaskShow;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectMilestone;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 35D: add, edit and delete in the staff portal, shown to those whose
 * role holds the permission (or, for tasks and milestones, a project lead),
 * and done by the API.
 */
class ManagePagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-14 02:00:00', 'UTC'));
        $this->user = User::factory()->staff()->create();
        $this->staff = Staff::factory()->create(['user_id' => $this->user->id, 'first_name' => 'Lia', 'last_name' => 'Ramos', 'preferred_name' => null]);
    }

    /** Give the Staff role these permissions, as an Administrator would on the Roles screen. */
    private function grant(string ...$names): void
    {
        Role::query()->where('name', Role::STAFF)->firstOrFail()->permissions()
            ->syncWithoutDetaching(Permission::query()->whereIn('name', $names)->pluck('id'));
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user->fresh(), 'web');
    }

    private function lead(Project $project, string $role = 'project_lead'): void
    {
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $this->staff->id, 'role' => ProjectMembershipRole::from($role)]);
    }

    // ---- With the defaults, nothing new appears -----------------------------

    public function test_with_the_default_permissions_staff_see_no_new_buttons(): void
    {
        $project = Project::factory()->create();
        $this->lead($project, 'member');
        $task = Task::factory()->create(['project_id' => $project->id, 'assignee_staff_id' => $this->staff->id]);
        $client = Client::factory()->create();
        $this->actingAs($this->user, 'web');

        $this->get('/app/tasks')->assertOk()->assertDontSee('New task')->assertDontSee('Whose tasks');
        $this->get('/app/tasks/'.$task->public_id)->assertOk()->assertDontSee('Edit task')->assertDontSee('Delete task');
        $this->get('/app/projects')->assertOk()->assertDontSee('New project')->assertDontSee('Whose projects');
        $this->get('/app/projects/'.$project->public_id)->assertOk()
            ->assertDontSee('Edit project')->assertDontSee('Add a member')->assertDontSee('New milestone')->assertDontSee('New task');
        $this->get('/app/clients')->assertOk()->assertDontSee('New client');
        $this->get('/app/clients/'.$client->public_id)->assertOk()->assertDontSee('Edit client')->assertDontSee('Add contact');
        $this->get('/app/announcements')->assertOk()->assertDontSee('Manage announcements');
    }

    // ---- Tasks ------------------------------------------------------------

    public function test_task_permissions_show_the_buttons_and_the_all_tab(): void
    {
        $other = Staff::factory()->create(['first_name' => 'Kai', 'last_name' => 'Tan', 'preferred_name' => null]);
        $theirs = Task::factory()->create(['title' => 'Their task', 'assignee_staff_id' => $other->id]);
        $this->grant('tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete');

        $this->get('/app/tasks')->assertSee('New task')->assertSee('Whose tasks')->assertDontSee('Their task');
        Livewire::test(Tasks::class)->call('showScope', 'all')->assertSee('Their task')->assertSee('Assigned to Kai Tan')
            ->set('search', 'zzz')->assertDontSee('Their task');
        $this->get('/app/tasks/'.$theirs->public_id)->assertSee('Edit task')->assertSee('Delete task');
        $this->get('/app/tasks/new')->assertOk()->assertSee('No project (independent task)');
    }

    public function test_new_task_with_an_assignee_and_a_project_then_edit_and_delete(): void
    {
        $project = Project::factory()->create(['name' => 'Cold room']);
        $kai = Staff::factory()->create(['first_name' => 'Kai', 'last_name' => 'Tan', 'preferred_name' => null]);
        ProjectMembership::factory()->create(['project_id' => $project->id, 'staff_id' => $kai->id]);
        $this->grant('tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete');

        Livewire::test(TaskForm::class)
            ->call('save')->assertHasErrors(['title' => 'Give the task a title.'])
            ->set('title', 'Fix the hinge')->set('project', $project->public_id)
            ->set('assigneeSearch', 'lia')->call('chooseAssignee', $this->staff->public_id, 'Lia Ramos')->call('save')
            // The API's own rule; in tests Livewire files its 422 under the API's field name.
            ->assertHasErrors(['assignee_staff_id' => "The assignee must be a member of the task's project."])
            ->set('assigneeSearch', 'kai')->assertSee('Kai Tan')
            ->call('chooseAssignee', Staff::query()->where('first_name', 'Kai')->value('public_id'), 'Kai Tan')
            ->set('priority', 'high')->set('dueDate', '2026-10-20')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $task = Task::query()->where('title', 'Fix the hinge')->sole();
        $this->assertSame($project->id, $task->project_id);
        $this->assertSame('Kai', $task->assignee->first_name);
        $this->assertSame('high', $task->priority->value);
        $this->assertSame('2026-10-20', $task->due_date->toDateString());

        Livewire::test(TaskForm::class, ['publicId' => $task->public_id])
            ->assertSet('title', 'Fix the hinge')->assertSet('assigneeName', 'Kai Tan')
            ->set('title', 'Fix both hinges')->call('clearAssignee')->set('status', 'in_progress')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Fix both hinges', $task->fresh()->title);
        $this->assertNull($task->fresh()->assignee_staff_id);

        $todo = Task::factory()->create();
        Livewire::test(TaskShow::class, ['publicId' => $todo->public_id])->call('delete')->assertRedirect(route('portal.tasks'));
        $this->assertModelMissing($todo);
    }

    public function test_a_refused_delete_shows_the_apis_message(): void
    {
        $this->grant('tasks.view', 'tasks.delete');
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                if ($method === 'DELETE') {
                    throw new PortalApiException(409, 'This task has work logs and cannot be deleted.');
                }

                return ['data' => str_starts_with($path, '/tasks/') ? [
                    'public_id' => 'T1', 'title' => 'Logged task', 'description' => null, 'status' => 'todo', 'priority' => 'normal',
                    'due_date' => null, 'completed_at' => null, 'project' => null, 'assignee' => null, 'created_by' => null,
                ] : ['tasks' => [], 'company_day' => ['date' => '2026-10-14']]];
            }
        });

        Livewire::test(TaskShow::class, ['publicId' => 'T1'])->assertSee('Delete task')->call('delete')
            ->assertSee('This task has work logs and cannot be deleted.')->assertNoRedirect();
    }

    public function test_a_project_lead_adds_and_edits_tasks_in_their_project_only(): void
    {
        $mine = Project::factory()->create(['name' => 'Led by Lia']);
        $this->lead($mine);
        $other = Project::factory()->create(['name' => 'Someone else']);
        $this->lead($other, 'member');
        $theirs = Task::factory()->create(['project_id' => $other->id, 'assignee_staff_id' => $this->staff->id]);
        $this->actingAs($this->user, 'web');

        $this->get('/app/tasks')->assertSee('New task');
        Livewire::test(TaskForm::class)
            ->assertSee('Led by Lia')->assertDontSee('Someone else')->assertDontSee('No project (independent task)')
            ->set('title', 'Lead task')->set('project', $mine->public_id)->call('save')->assertHasNoErrors();
        $created = Task::query()->where('title', 'Lead task')->sole();

        $this->get('/app/tasks/'.$created->public_id)->assertSee('Edit task')->assertDontSee('Delete task');
        $this->get('/app/tasks/'.$theirs->public_id)->assertDontSee('Edit task');
        $this->get('/app/projects/'.$mine->public_id)->assertSee('New task')->assertSee('New milestone')->assertDontSee('Add a member');
    }

    // ---- Projects ---------------------------------------------------------

    public function test_project_permissions_new_edit_and_the_all_tab(): void
    {
        Project::factory()->create(['name' => 'Not mine']);
        $client = Client::factory()->create(['name' => 'Harbour Foods']);
        $this->grant('projects.view', 'projects.create', 'projects.update', 'projects.delete', 'clients.view');

        $this->get('/app/projects')->assertSee('New project')->assertSee('Whose projects');
        Livewire::test(Projects::class)->call('showScope', 'all')->assertSee('Not mine');

        Livewire::test(ProjectForm::class)
            ->assertSee('Harbour Foods')
            ->call('save')->assertHasErrors(['name' => 'Give the project a name.'])
            ->set('name', 'Dock repairs')->set('client', $client->public_id)->set('status', 'completed')
            ->call('save')->assertHasErrors(['completedDate'])
            ->set('completedDate', '2026-10-10')->call('save')->assertHasNoErrors()->assertRedirect();
        $project = Project::query()->where('name', 'Dock repairs')->sole();
        $this->assertSame($client->id, $project->client_id);

        Livewire::test(ProjectForm::class, ['publicId' => $project->public_id])
            ->assertSet('name', 'Dock repairs')->set('status', 'active')->set('completedDate', '')->set('notes', 'Phase 1')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Phase 1', $project->fresh()->notes);

        // Edit, but no members without projects.members.
        $this->get('/app/projects/'.$project->public_id)->assertSee('Edit project')->assertDontSee('Add a member');

        Livewire::test(ProjectShow::class, ['publicId' => $project->public_id])->call('delete')->assertRedirect(route('portal.projects'));
        $this->assertModelMissing($project);
    }

    public function test_members_and_milestones_on_the_project_page(): void
    {
        $project = Project::factory()->create();
        $kai = Staff::factory()->create(['first_name' => 'Kai', 'last_name' => 'Tan', 'preferred_name' => null]);
        $milestone = ProjectMilestone::factory()->create(['project_id' => $project->id, 'title' => 'Survey']);
        $this->grant('projects.view', 'projects.update', 'projects.members', 'staff.view');

        Livewire::test(ProjectShow::class, ['publicId' => $project->public_id])
            ->call('addMember')->assertHasErrors(['memberSearch'])
            ->set('memberSearch', 'kai')->assertSee('Kai Tan')
            ->call('chooseMember', $kai->public_id, 'Kai Tan')->set('newMemberRole', 'project_lead')
            ->call('addMember')->assertSet('savedMessage', 'Kai Tan added.')
            ->call('setMemberRole', $kai->public_id, 'member')->assertSet('savedMessage', 'Role changed.')
            ->call('setMilestoneStatus', $milestone->public_id, 'completed')->assertSet('savedMessage', 'Milestone updated.')
            ->set('milestoneTitle', 'Handover')->set('milestoneDue', '2026-11-30')->call('addMilestone')->assertSet('savedMessage', 'Milestone added.')
            ->call('removeMilestone', $milestone->public_id)->assertSet('savedMessage', 'Milestone removed.')
            ->call('removeMember', $kai->public_id)->assertSet('savedMessage', 'Member removed.');

        $this->assertFalse(ProjectMembership::query()->where('staff_id', $kai->id)->exists());
        $this->assertModelMissing($milestone);
        $this->assertSame('Handover', $project->milestones()->sole()->title);
    }

    // ---- Clients ----------------------------------------------------------

    public function test_clients_and_contacts(): void
    {
        $this->grant('clients.create', 'clients.update', 'clients.delete');
        $this->get('/app/clients')->assertSee('New client');

        Livewire::test(ClientForm::class)
            ->set('email', 'not-an-email')->call('save')->assertHasErrors(['name', 'email'])
            ->set('name', 'Pier Logistics')->set('email', 'ops@pier.test')->set('city', 'Cebu')->call('save')->assertHasNoErrors();
        $client = Client::query()->where('name', 'Pier Logistics')->sole();
        $this->assertSame('Cebu', $client->city);
        $this->get('/app/clients/'.$client->public_id)->assertSee('Edit client')->assertSee('Add contact')->assertSee('Delete client');

        Livewire::test(ClientForm::class, ['publicId' => $client->public_id])->set('status', 'inactive')->call('save')->assertHasNoErrors();
        $this->assertSame('inactive', $client->fresh()->status->value);

        Livewire::test(ContactForm::class, ['clientId' => $client->public_id])
            ->assertSee('For Pier Logistics')
            ->call('save')->assertHasErrors(['firstName', 'lastName'])
            ->set('firstName', 'Jane')->set('lastName', 'Doe')->set('isPrimary', true)->call('save')->assertHasNoErrors();
        $contact = Contact::query()->where('first_name', 'Jane')->sole();
        $this->assertTrue($contact->is_primary);

        Livewire::test(ContactForm::class, ['clientId' => $client->public_id, 'publicId' => $contact->public_id])
            ->assertSet('firstName', 'Jane')->set('jobTitle', 'Ops lead')->set('status', 'inactive')->call('save')->assertHasNoErrors();
        $this->assertSame('Ops lead', $contact->fresh()->job_title);
        Livewire::test(ClientShow::class, ['publicId' => $client->public_id])->assertSee('Jane Doe')->assertSee('Inactive')
            ->call('removeContact', $contact->public_id)->assertSet('savedMessage', 'Contact removed.');
        $this->assertModelMissing($contact);

        Livewire::test(ClientShow::class, ['publicId' => $client->public_id])->call('delete')->assertRedirect(route('portal.clients'));
        $this->assertModelMissing($client);
    }

    public function test_a_contact_of_another_client_is_not_found(): void
    {
        $this->grant('clients.update');
        $contact = Contact::factory()->create();
        Livewire::test(ContactForm::class, ['clientId' => Client::factory()->create()->public_id, 'publicId' => $contact->public_id])
            ->assertSet('notFound', true)->assertSee('This contact no longer exists.');
    }

    // ---- Announcements ----------------------------------------------------

    public function test_announcements_draft_edit_publish_archive_and_delete(): void
    {
        $department = Department::factory()->create(['name' => 'Operations']);
        $this->grant('announcements.view-all', 'announcements.create', 'announcements.publish', 'announcements.delete');

        $this->get('/app/announcements')->assertSee('Manage announcements');
        $this->get('/app/announcements/manage')->assertOk()->assertSee('New announcement');

        Livewire::test(AnnouncementForm::class)
            ->call('save')->assertHasErrors(['title', 'body'])
            ->set('title', 'Canteen opens Monday')->set('body', 'Lunch from 11:30.')->set('audience', 'scoped')
            ->assertSee('Operations')->call('save')->assertHasErrors(['audience'])
            ->set('departments', [$department->public_id])->call('save')->assertHasNoErrors()->assertRedirect();
        $announcement = Announcement::query()->where('title', 'Canteen opens Monday')->sole();
        $this->assertSame('scoped', $announcement->audience_type->value);

        Livewire::test(AnnouncementForm::class, ['publicId' => $announcement->public_id])
            ->assertSet('departments', [$department->public_id])->set('body', 'Lunch from 11:00.')->call('save')->assertHasNoErrors();

        Livewire::test(AnnouncementsManage::class)->assertSee('Canteen opens Monday')->assertSee('Selected departments or teams');
        Livewire::test(AnnouncementManage::class, ['publicId' => $announcement->public_id])
            ->assertSee('Lunch from 11:00.')->assertSee('For: Operations')->assertSee('Edit draft')
            ->call('publish')->assertSet('savedMessage', 'Announcement published. Staff can see it now.')
            ->assertDontSee('Edit draft')->assertSee('Archive')
            ->call('archive')->assertSet('savedMessage', 'Announcement archived.');
        $this->assertSame('archived', $announcement->fresh()->status->value);

        Livewire::test(AnnouncementForm::class, ['publicId' => $announcement->public_id])->assertSee('Only a draft can be edited.');

        $draft = Announcement::factory()->create();
        Livewire::test(AnnouncementManage::class, ['publicId' => $draft->public_id])->call('delete')->assertRedirect(route('portal.announcements.manage'));
        $this->assertModelMissing($draft);
    }

    public function test_publish_only_permission_cannot_edit_or_delete(): void
    {
        $draft = Announcement::factory()->create();
        $this->grant('announcements.view-all', 'announcements.publish');

        $this->get('/app/announcements/manage')->assertDontSee('New announcement');
        Livewire::test(AnnouncementManage::class, ['publicId' => $draft->public_id])
            ->assertSee('Publish')->assertDontSee('Edit draft')->assertDontSee('Delete draft');
    }

    public function test_a_refused_action_shows_the_apis_message(): void
    {
        $this->grant('tasks.view', 'tasks.create');
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                if ($method === 'POST') {
                    throw new PortalApiException(403, 'You do not have permission to create a task here.');
                }

                return ['data' => [], 'meta' => ['last_page' => 1]];
            }
        });

        Livewire::test(TaskForm::class)->set('title', 'X')->call('save')
            ->assertSee('You do not have permission to create a task here.')->assertNoRedirect();
    }
}
