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
        $user = User::factory()->administrator()->create();
        $this->actingAs($user);

        $this->get('/login')->assertRedirect(route('home'));
    }

    public function test_admin_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->administrator()->create([
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
        $user = User::factory()->administrator()->create([
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
        $user = User::factory()->administrator()->create([
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
        $user = User::factory()->staff()->create([
            'password' => Hash::make('correct-password'),
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
        $user = User::factory()->administrator()->suspended()->create([
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
        $user = User::factory()->administrator()->inactive()->create([
            'password' => Hash::make('correct-password'),
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Phase 22, F-01: a suspended/inactive Admin account authenticated
     * with the *correct* password must receive an error message
     * byte-identical to a wrong-password attempt — otherwise the
     * response itself discloses that the account exists and is
     * deactivated, contradicting 05_SECURITY_MODEL.md's no-enumeration
     * guarantee.
     */
    public function test_suspended_admin_account_gets_the_same_error_message_as_wrong_password(): void
    {
        $suspended = User::factory()->administrator()->suspended()->create([
            'password' => Hash::make('correct-password'),
        ]);
        $active = User::factory()->administrator()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $suspendedAttempt = Livewire::test(LoginForm::class)
            ->set('email', $suspended->email)
            ->set('password', 'correct-password')
            ->call('login');

        $wrongPasswordAttempt = Livewire::test(LoginForm::class)
            ->set('email', $active->email)
            ->set('password', 'wrong-password')
            ->call('login');

        $suspendedMessage = $suspendedAttempt->errors()->first('email');
        $wrongPasswordMessage = $wrongPasswordAttempt->errors()->first('email');

        $this->assertNotNull($suspendedMessage);
        $this->assertSame($wrongPasswordMessage, $suspendedMessage);
        $this->assertSame('These credentials do not match our records.', $suspendedMessage);
        $this->assertGuest();
    }

    public function test_inactive_admin_account_gets_the_same_error_message_as_wrong_password(): void
    {
        $inactive = User::factory()->administrator()->inactive()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $attempt = Livewire::test(LoginForm::class)
            ->set('email', $inactive->email)
            ->set('password', 'correct-password')
            ->call('login');

        $message = $attempt->errors()->first('email');

        $this->assertSame('These credentials do not match our records.', $message);
        $this->assertGuest();
    }

    /**
     * Confirms the F-01 message unification never allows a
     * suspended/inactive Admin account to actually authenticate — the
     * fix changes only the disclosed message, never the enforcement.
     */
    public function test_suspended_admin_account_never_gains_a_session_regardless_of_message_unification(): void
    {
        $user = User::factory()->administrator()->suspended()->create([
            'password' => Hash::make('correct-password'),
        ]);

        Livewire::test(LoginForm::class)
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login');

        $this->assertGuest();
        $this->get('/home')->assertRedirect('/login');
    }

    public function test_login_is_rate_limited_after_repeated_failures(): void
    {
        $user = User::factory()->administrator()->create([
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
        $user = User::factory()->administrator()->create();

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
        $user = User::factory()->administrator()->create();
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
        $user = User::factory()->administrator()->create();

        $this->actingAs($user)
            ->get('/home')
            ->assertOk()
            ->assertSee($user->name);
    }
}
