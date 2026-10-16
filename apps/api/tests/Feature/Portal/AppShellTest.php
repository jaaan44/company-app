<?php

namespace Tests\Feature\Portal;

use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The portal as an app (Phase 34F): the five tabs, the More screen in the
 * mobile app's order, the app bar's back arrow, toasts, and what makes the
 * portal installable.
 */
class AppShellTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->staff()->create();
    }

    private function assertCurrentTab(TestResponse $response, string $path): void
    {
        $page = $response->assertOk()->getContent();

        // Once in the sidebar, once in the bottom bar — and no other tab is current.
        $this->assertSame(2, preg_match_all('#aria-current="page"#', $page));
        $this->assertSame(2, preg_match_all('#href="[^"]*'.preg_quote($path, '#').'"\s+aria-current="page"#', $page));
    }

    public function test_every_page_has_the_five_tabs_in_the_mobile_order(): void
    {
        $this->actingAs($this->user, 'web')->get('/app')
            ->assertSee('aria-label="Main"', false)
            ->assertSeeInOrder(['Home', 'Tasks', 'Schedule', 'Messages', 'More']);
    }

    public function test_the_current_tab_follows_the_page(): void
    {
        $this->actingAs($this->user, 'web');

        $this->assertCurrentTab($this->get('/app'), '/app');
        $this->assertCurrentTab($this->get('/app/tasks'), '/app/tasks');
        $this->assertCurrentTab($this->get('/app/schedule'), '/app/schedule');
        $this->assertCurrentTab($this->get('/app/messages'), '/app/messages');
        $this->assertCurrentTab($this->get('/app/messages/new'), '/app/messages');
        $this->assertCurrentTab($this->get('/app/more'), '/app/more');
        $this->assertCurrentTab($this->get('/app/profile'), '/app/more');
        $this->assertCurrentTab($this->get('/app/work-logs/new'), '/app/more');
    }

    public function test_more_lists_the_pages_in_the_mobile_order(): void
    {
        $response = $this->actingAs($this->user, 'web')->get('/app/more');
        // No toast: the only status region is the hidden connection banner (Phase 37).
        $this->assertSame(1, substr_count((string) $response->getContent(), 'role="status"'));
        $response->assertSee('id="connection-banner" role="status" aria-live="polite" hidden', false)
            ->assertOk()
            ->assertSeeInOrder([
                'My profile', 'My status', 'Announcements', 'Company news', 'Notifications',
                'Reports', 'Service and incident reports', 'Leave', 'Balances and requests',
                'Staff directory', 'Find a colleague', 'My work logs', 'Time you have logged',
                'Projects', 'Clients', 'Company clients and contacts', 'Log out',
            ])
            ->assertSee('<title>More — Company App</title>', false)
            ->assertDontSee('font-medium">Admin</span>', false);
    }

    public function test_more_links_to_admin_only_for_administrators(): void
    {
        $this->actingAs(User::factory()->administrator()->create(), 'web')->get('/app/more')
            ->assertSee('font-medium">Admin</span>', false)
            ->assertSee('data-no-navigate', false);
    }

    public function test_pages_on_more_get_a_back_arrow_to_more_and_tabs_do_not(): void
    {
        $this->actingAs($this->user, 'web');

        foreach (['/app/profile', '/app/status', '/app/announcements', '/app/notifications', '/app/reports', '/app/leave', '/app/staff', '/app/work-logs', '/app/projects', '/app/clients'] as $url) {
            $this->get($url)->assertOk()->assertSee('aria-label="Back to More"', false);
        }
        foreach (['/app', '/app/tasks', '/app/schedule', '/app/messages', '/app/more'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('aria-label="Back', false);
        }
    }

    public function test_a_sub_page_sets_its_own_back_arrow(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/work-logs/new')
            ->assertSee('aria-label="Back to My work logs"', false)
            ->assertDontSee('aria-label="Back to More"', false);
        $this->get('/app/staff/'.Staff::factory()->create()->public_id)
            ->assertSee('aria-label="Back to Staff directory"', false)
            ->assertDontSee('aria-label="Back to More"', false);
    }

    public function test_the_app_bar_shows_the_page_title(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/leave')
            ->assertSee('<p class="truncate text-lg font-semibold">Leave</p>', false);
    }

    public function test_a_flashed_status_shows_as_a_toast(): void
    {
        $this->actingAs($this->user, 'web')->withSession(['status' => 'Work log saved.'])->get('/app/more')
            ->assertSeeInOrder(['role="status"', 'Work log saved.'], false);
    }

    public function test_the_portal_and_sign_in_page_are_installable(): void
    {
        foreach ([$this->get('/login'), $this->actingAs($this->user, 'web')->get('/app')] as $response) {
            $response->assertSee('<link rel="manifest" href="/manifest.json">', false)
                ->assertSee('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">', false)
                ->assertSee('viewport-fit=cover', false);
        }
    }

    public function test_the_manifest_starts_at_the_portal_and_its_icons_exist(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('/app', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        foreach ([...array_column($manifest['icons'], 'src'), '/icons/apple-touch-icon.png'] as $src) {
            $path = public_path(ltrim($src, '/'));
            $this->assertFileExists($path);
            $this->assertSame('image/png', mime_content_type($path));
        }
        $this->assertSame([192, 192], array_slice((array) getimagesize(public_path('icons/icon-192.png')), 0, 2));
        $this->assertSame([512, 512], array_slice((array) getimagesize(public_path('icons/maskable-512.png')), 0, 2));
    }
}
