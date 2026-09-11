<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Livewire\Auth\LoginForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_accessible_to_guests(): void
    {
        $this->get('/login')->assertOk()->assertSeeLivewire(LoginForm::class);
    }

    public function test_authenticated_admin_visiting_login_is_not_blocked(): void
    {
        // Guest-only route; a real product may redirect authenticated
        // users away, but that's a UX nicety, not an auth boundary.
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        $this->get('/login')->assertRedirect(route('home'));
    }

    public function test_admin_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->admin()->create([
            'password' => Hash::make('correct-password'),
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_session_id_is_regenerated_after_successful_login(): void
    {
        $user = User::factory()->admin()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $this->get('/login');
        $originalSessionId = session()->getId();

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login');

        $this->assertNotSame($originalSessionId, session()->getId());
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->admin()->create([
            'password' => Hash::make('correct-password'),
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_non_admin_account_is_rejected_from_admin_backoffice(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'is_admin' => false,
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_suspended_admin_account_cannot_log_in(): void
    {
        $user = User::factory()->admin()->suspended()->create([
            'password' => Hash::make('correct-password'),
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_admin_account_cannot_log_in(): void
    {
        $user = User::factory()->admin()->inactive()->create([
            'password' => Hash::make('correct-password'),
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $user = User::factory()->admin()->create([
            'password' => Hash::make('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(LoginForm::class)
                ->set('email', $user->email)
                ->set('password', 'wrong-password')
                ->call('login');
        }

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();

        RateLimiter::clear(mb_strtolower($user->email).'|127.0.0.1');
    }

    public function test_logout_ends_the_admin_session(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_unauthenticated_user_cannot_access_the_home_placeholder(): void
    {
        $this->get('/home')->assertRedirect('/login');
    }

    public function test_suspended_account_loses_access_to_the_home_placeholder_mid_session(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        // Direct attribute assignment (not update()) since 'status' is
        // deliberately not mass-assignable.
        $user->status = AccountStatus::Suspended;
        $user->save();

        // SessionGuard caches its resolved user for the lifetime of the
        // guard instance; without this, the request below would reuse the
        // user resolved by actingAs() above instead of re-authenticating
        // against the (now suspended) database state — a testing artifact
        // only, not a production behavior.
        $this->app['auth']->forgetGuards();

        $this->get('/home')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_authenticated_admin_can_view_the_home_placeholder(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertSee($user->name);
    }
}
