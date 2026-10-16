<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\LoginForm;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Tests\TestCase;

/**
 * Phase 37 (DEC-067 H-2…H-4): "Keep me signed in", the way back after a
 * lost session, and the app's own error pages.
 */
class StaySignedInTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->staff()->create(['email' => 'lia@company.test', 'password' => 'the-current-password']);
        Staff::factory()->create(['user_id' => $this->user->id]);
    }

    private function rememberCookie(): ?SymfonyCookie
    {
        $name = Auth::guard('web')->getRecallerName();

        return collect(Cookie::getQueuedCookies())->first(fn (SymfonyCookie $cookie) => $cookie->getName() === $name);
    }

    /** A browser that comes back with only its "Keep me signed in" cookie. */
    private function returningBrowser(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->withCookie(Auth::guard('web')->getRecallerName(), $this->user->id.'|'.$token.'|'.$this->user->getAuthPassword());
    }

    private function signInRemembered(): string
    {
        Auth::shouldUse('web');
        Livewire::test(LoginForm::class)
            ->set('email', 'lia@company.test')->set('password', 'the-current-password')->set('remember', true)
            ->call('login')->assertRedirect(route('portal.home'));

        $token = $this->user->fresh()->getRememberToken();
        $this->assertIsString($token);

        return $token;
    }

    // ---- Keep me signed in ------------------------------------------------

    public function test_the_sign_in_page_offers_keep_me_signed_in_unticked(): void
    {
        $this->get('/login')->assertOk()
            ->assertSeeInOrder(['Keep me signed in on this device', 'For 30 days. Leave it off on a shared phone or computer.'])
            ->assertSee('inputmode="email"', false)->assertSee('enterkeyhint="go"', false);

        Livewire::test(LoginForm::class)->assertSet('remember', false);
    }

    public function test_without_it_no_remember_cookie_is_set(): void
    {
        Livewire::test(LoginForm::class)
            ->set('email', 'lia@company.test')->set('password', 'the-current-password')
            ->call('login')->assertRedirect(route('portal.home'));

        $this->assertAuthenticatedAs($this->user);
        $this->assertNull($this->rememberCookie());
    }

    public function test_with_it_a_30_day_cookie_signs_the_browser_back_in(): void
    {
        $token = $this->signInRemembered();

        $cookie = $this->rememberCookie();
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $cookie->getExpiresTime(), 120);
        $this->assertSame(60 * 24 * 30, LoginForm::REMEMBER_MINUTES);

        $this->returningBrowser($token)->get('/app')->assertOk();
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_wrong_remember_cookie_signs_nobody_in(): void
    {
        $this->signInRemembered();

        $this->returningBrowser('not-the-token')->get('/app')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_signing_out_ends_it(): void
    {
        $token = $this->signInRemembered();
        $this->assertInstanceOf(SessionGuard::class, Auth::guard('web'));

        $this->post('/logout')->assertRedirect('/login');

        $this->assertNotSame($token, $this->user->fresh()->getRememberToken());
        $this->returningBrowser($token)->get('/app')->assertRedirect('/login');
    }

    public function test_changing_my_password_ends_it(): void
    {
        $token = $this->signInRemembered();

        Sanctum::actingAs($this->user);
        $this->putJson('/api/v1/me/password', [
            'current_password' => 'the-current-password', 'password' => 'a-long-chosen-pass', 'password_confirmation' => 'a-long-chosen-pass',
        ])->assertOk();

        $this->user->refresh();
        $this->assertNotSame($token, $this->user->getRememberToken());
        $this->returningBrowser($token)->get('/app')->assertRedirect('/login');
    }

    public function test_a_password_reset_or_a_suspension_by_an_administrator_ends_it(): void
    {
        $admin = User::factory()->administrator()->create();

        foreach (['reset', 'suspend'] as $action) {
            $this->app['auth']->forgetGuards();
            $this->flushSession();
            $token = $this->signInRemembered();

            Sanctum::actingAs($admin);
            if ($action === 'reset') {
                $this->postJson("/api/v1/users/{$this->user->public_id}/reset-password")->assertOk();
            } else {
                $this->putJson("/api/v1/users/{$this->user->public_id}/status", ['status' => 'suspended'])->assertOk();
            }

            $this->user->refresh();
            $this->assertNotSame($token, $this->user->getRememberToken(), $action);
            $this->returningBrowser($token)->get('/app')->assertRedirect('/login');

            if ($action === 'reset') {
                $this->user->forceFill(['password' => 'the-current-password', 'must_change_password' => false])->save();
            }
        }
    }

    // ---- Back to where you were (H-3) -------------------------------------

    public function test_after_signing_in_you_return_to_the_page_you_asked_for(): void
    {
        $this->get('/app/tasks')->assertRedirect('/login');
        $this->get('/login')->assertSee('Please sign in to continue.');

        Livewire::test(LoginForm::class)
            ->set('email', 'lia@company.test')->set('password', 'the-current-password')
            ->call('login')->assertRedirect(url('/app/tasks'));
    }

    public function test_an_account_that_must_choose_a_password_goes_there_first(): void
    {
        $this->user->forceFill(['must_change_password' => true])->save();
        $this->get('/app/tasks')->assertRedirect('/login');

        Livewire::test(LoginForm::class)
            ->set('email', 'lia@company.test')->set('password', 'the-current-password')
            ->call('login')->assertRedirect(route('password.choose'));
    }

    // ---- Error pages (H-4) --------------------------------------------------

    public function test_every_error_page_is_ours_and_leads_somewhere(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_phase37/{status}', fn (int $status) => abort($status));

        $pages = [
            403 => ['You can\'t open this page', '/app', 'Go to Home'],
            404 => ['Page not found', '/app', 'Go to Home'],
            419 => ['This page has expired', '/login', 'Continue'],
            429 => ['Too many tries', '/app', 'Go to Home'],
            500 => ['Something went wrong', '/app', 'Go to Home'],
            503 => ['Back soon', '/app', 'Go to Home'],
        ];

        foreach ($pages as $status => [$title, $href, $action]) {
            $this->get("/_phase37/{$status}")->assertStatus($status)
                ->assertSee('<title>'.e($title), false)
                ->assertSee('href="'.$href.'"', false)->assertSee($action)
                ->assertDontSee('wire:', false)
                ->assertDontSee('Symfony')->assertDontSee('Stack trace');
        }

        $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found')->assertSee('Go to Home');
    }

    public function test_every_layout_carries_the_hidden_connection_banner(): void
    {
        $this->get('/login')->assertSee('id="connection-banner"', false)
            ->assertSee('You\'re offline. Changes won\'t be saved until you reconnect.', false);

        $this->actingAs($this->user, 'web');
        $this->get('/app')->assertSee('id="connection-banner"', false);

        $this->actingAs(User::factory()->administrator()->create(), 'web');
        $this->get('/admin')->assertSee('id="connection-banner"', false);
    }
}
