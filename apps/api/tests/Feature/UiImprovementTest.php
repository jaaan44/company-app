<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\LeaveRequests;
use App\Livewire\Admin\OperationsReports;
use App\Livewire\Admin\ReportView;
use App\Livewire\Admin\ScheduleEntries;
use App\Livewire\Admin\StaffList;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 37B — Web UI/UX Improvement (DEC-068): the shared controls, the
 * Admin dashboard's order and links, the section pages, Clear filters and
 * the password toggle. Styles themselves are checked in the browser; these
 * tests pin the markup they hang on.
 */
class UiImprovementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-16 02:00:00', 'UTC'));
        $this->admin = User::factory()->administrator()->create(['name' => 'Ada Admin']);
    }

    // ---- Shared controls ------------------------------------------------

    public function test_buttons_and_fields_use_the_shared_styles(): void
    {
        $this->actingAs($this->admin, 'web');

        $html = $this->get('/admin/people/staff')->assertOk()->getContent();
        $this->assertStringContainsString('class="btn btn-primary"', $html);
        $this->assertStringContainsString('class="field mt-1 w-full"', $html);
        $this->assertStringNotContainsString('rounded-md border border-line bg-raised px-3', $html);

        // A list's main action: a pill on wider screens, the round "+" on phones.
        $this->blade('<x-portal.fab href="/app/tasks/new" label="New task" />')
            ->assertSee('class="btn btn-primary max-md:hidden"', false)
            ->assertSee('aria-label="New task"', false);
    }

    public function test_a_page_without_a_staff_profile_says_so_in_the_shared_notice(): void
    {
        $this->actingAs($this->admin, 'web')->get('/app/tasks')->assertOk()
            ->assertSee('<p class="rounded-xl border border-line bg-raised p-5">No staff profile is linked to this account.</p>', false);
    }

    // ---- Admin dashboard -------------------------------------------------

    public function test_the_dashboard_puts_what_needs_a_decision_first_and_links_it(): void
    {
        $this->actingAs($this->admin, 'web');

        Livewire::test(Dashboard::class)
            ->assertSeeInOrder(['Needs attention', 'Leave', 'Open incidents', 'Overdue tasks', 'Overview', 'From', 'People'])
            ->assertSeeHtml('href="'.route('admin.leave-requests').'"')
            ->assertSeeHtml('href="'.route('admin.incidents').'"')
            ->assertSeeHtml('href="'.route('admin.reports.show', ['report' => 'tasks', 'f' => ['overdue' => '1']]).'"')
            ->assertSeeHtml('href="'.route('admin.service-reports').'"');
    }

    public function test_dashboard_figures_link_only_for_those_who_may_open_the_section(): void
    {
        // HR shows only to holders of a leave permission; Operations to anyone in the Backoffice (as in the navigation).
        $role = Role::query()->create(['name' => 'clerk', 'label' => 'Clerk']);
        $role->permissions()->sync(Permission::query()->whereIn('name', ['admin.access', 'clients.view'])->pluck('id'));
        $this->actingAs(User::factory()->create(['role_id' => $role->id]), 'web');

        Livewire::test(Dashboard::class)
            ->assertDontSeeHtml('href="'.route('admin.leave-requests').'"')
            ->assertSeeHtml('href="'.route('admin.incidents').'"');
    }

    // ---- Section pages ---------------------------------------------------

    public function test_section_items_in_the_staff_portal_say_so_and_load_in_full(): void
    {
        $this->actingAs($this->admin, 'web');

        $clients = $this->get('/admin/clients')->assertOk()->assertSee('Opens in the staff portal')->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('portal.clients'), '#').'"\s+data-no-navigate#', $clients);

        $this->get('/admin/hr')->assertOk()->assertDontSee('Opens in the staff portal');
        $this->get('/admin/people')->assertOk()
            ->assertSee('correct their status and review their check-ins')
            ->assertDontSee('Location check-ins');
    }

    // ---- Clear filters ---------------------------------------------------

    public function test_clear_filters_returns_each_list_to_its_starting_filters(): void
    {
        $this->actingAs($this->admin, 'web');

        Livewire::test(StaffList::class)->assertDontSee('Clear filters')
            ->set('search', 'Lia')->set('status', 'inactive')->assertSee('Clear filters')
            ->call('clearFilters')->assertSet('search', '')->assertSet('status', '')->assertDontSee('Clear filters');

        Livewire::test(LeaveRequests::class)->assertDontSee('Clear filters')
            ->set('status', 'approved')->set('from', '2026-10-01')->assertSee('Clear filters')
            ->call('clearFilters')->assertSet('status', 'pending')->assertSet('from', '')->assertDontSee('Clear filters');

        Livewire::test(OperationsReports::class, ['kind' => 'incidents'])->assertDontSee('Clear filters')
            ->set('status', '')->assertSee('Clear filters')
            ->call('clearFilters')->assertSet('status', 'open')->assertDontSee('Clear filters');

        Livewire::test(ReportView::class, ['report' => 'tasks'])->assertDontSee('Clear filters')
            ->set('filters.overdue', '1')->assertSee('Clear filters')
            ->call('clearFilters')->assertSet('filters', [])->assertDontSee('Clear filters');

        Livewire::test(ScheduleEntries::class)->assertSet('from', '2026-10-16')->assertDontSee('Clear filters')
            ->set('type', 'meeting')->set('from', '2026-10-01')->assertSee('Clear filters')
            ->call('clearFilters')->assertSet('type', '')->assertSet('from', '2026-10-16')->assertDontSee('Clear filters');
    }

    // ---- Sign-in ---------------------------------------------------------

    public function test_the_password_can_be_shown_and_starts_hidden(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('<input type="password" id="password"', false)
            ->assertSee('aria-controls="password"', false)
            ->assertSee('<span class="sr-only"> password</span>', false);
    }

    // ---- Messages badge and report sorting (Phase 37B, approved after review) ----

    public function test_the_messages_tab_shows_the_unread_count(): void
    {
        $user = User::factory()->staff()->create();
        $me = Staff::factory()->create(['user_id' => $user->id]);
        $other = Staff::factory()->create();
        $conversation = Conversation::factory()->create();
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $me->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $other->id]);
        Message::factory()->count(3)->create(['conversation_id' => $conversation->id, 'sender_staff_id' => $other->id]);

        $this->actingAs($user, 'web')->get('/app/more')->assertOk()
            ->assertSee('3<span class="sr-only"> unread</span>', false)   // the sidebar
            ->assertSee('<span class="sr-only">, 3 unread</span>', false); // the tab bar

        // No staff profile: no badge, and the page still opens.
        $this->actingAs($this->admin, 'web')->get('/app/more')->assertOk()->assertDontSee(' unread</span>', false);
    }

    public function test_a_report_sorts_by_its_column_headers(): void
    {
        Task::factory()->create(['title' => 'Replace valve']);
        $this->actingAs($this->admin, 'web');

        Livewire::test(ReportView::class, ['report' => 'tasks'])
            ->assertSeeHtml('aria-sort="none"')
            ->call('sortBy', 'title')->assertSet('sort', 'title')->assertSeeHtml('aria-sort="ascending"')
            ->call('sortBy', 'title')->assertSet('sort', '-title')->assertSeeHtml('aria-sort="descending"')
            ->assertSeeHtml('sort=-title') // the CSV keeps the order
            ->call('sortBy', 'status')->assertSet('sort', '-title'); // not a sortable column
    }
}
