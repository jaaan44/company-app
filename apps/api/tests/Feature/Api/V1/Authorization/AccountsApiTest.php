<?php

namespace Tests\Feature\Api\V1\Authorization;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sign-in accounts and passwords (Phase 36A, DEC-066 M-3…M-5, M-8).
 */
class AccountsApiTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARY = '/^[A-HJ-NP-Za-km-np-z2-9]{4}(-[A-HJ-NP-Za-km-np-z2-9]{4}){3}$/';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->administrator()->create(['name' => 'Ada Admin', 'email' => 'ada@company.test']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createAccount(array $overrides = []): array
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/v1/users', $overrides + ['name' => 'Nina Novak', 'email' => 'nina@company.test', 'role' => 'staff'])
            ->assertCreated()->json('data');
    }

    private function assertNotInAuditLog(string $secret): void
    {
        foreach (AuditLog::query()->get() as $entry) {
            $this->assertStringNotContainsString($secret, json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR));
        }
    }

    // ---- Who may use it ---------------------------------------------------

    public function test_only_administrators_may_manage_accounts(): void
    {
        $target = User::factory()->staff()->create();

        foreach ([User::factory()->manager()->create(), User::factory()->staff()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user);
            $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@company.test', 'role' => 'staff'])->assertForbidden();
            $this->postJson("/api/v1/users/{$target->public_id}/reset-password")->assertForbidden();
            $this->putJson("/api/v1/users/{$target->public_id}/status", ['status' => 'suspended'])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/users', [])->assertUnauthorized();
        $this->putJson('/api/v1/me/password', [])->assertUnauthorized();
        $this->assertSame(AccountStatus::Active, $target->fresh()->status);
    }

    // ---- Create -----------------------------------------------------------

    public function test_creating_an_account_returns_a_temporary_password_once_and_stores_only_its_hash(): void
    {
        $staff = Staff::factory()->create();
        $data = $this->createAccount(['staff_id' => $staff->public_id]);

        $temporary = $data['temporary_password'];
        $this->assertMatchesRegularExpression(self::TEMPORARY, $temporary);
        $this->assertSame('staff', $data['role']['name']);
        $this->assertTrue($data['must_change_password']);
        $this->assertSame($staff->public_id, $data['staff']['public_id']);

        $user = User::query()->where('email', 'nina@company.test')->firstOrFail();
        $this->assertNotSame($temporary, $user->password);
        $this->assertTrue(Hash::check($temporary, $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame(AccountStatus::Active, $user->status);
        $this->assertSame($user->id, $staff->fresh()->user_id);

        $entry = AuditLog::query()->where('action', 'user.created')->sole();
        $this->assertSame($user->public_id, $entry->entity_public_id);
        $this->assertSame('staff', $entry->after['role']);
        $this->assertNotInAuditLog($temporary);

        // Never shown again.
        $listed = $this->getJson('/api/v1/users?q=nina')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('temporary_password', $listed);
        $this->assertStringNotContainsString($temporary, $this->getJson('/api/v1/users')->getContent());
    }

    public function test_creating_refuses_a_taken_email_a_linked_staff_member_and_a_bad_role(): void
    {
        $linked = Staff::factory()->create(['user_id' => User::factory()->staff()->create()->id]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', ['name' => 'Ada', 'email' => 'ada@company.test', 'role' => 'staff'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'An account with this email already exists.');
        $this->postJson('/api/v1/users', ['name' => 'Lin', 'email' => 'lin@company.test', 'role' => 'staff', 'staff_id' => $linked->public_id])
            ->assertUnprocessable()->assertJsonPath('errors.staff_id.0', 'This staff member already has a sign-in account.');
        $this->postJson('/api/v1/users', ['name' => 'Lin', 'email' => 'lin@company.test', 'role' => 'owner'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/api/v1/users', ['email' => 'not-an-email', 'role' => 'staff'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);

        $this->assertSame(0, User::query()->where('email', 'lin@company.test')->count());
    }

    // ---- Must change password ----------------------------------------------

    public function test_the_phone_app_refuses_an_account_that_must_choose_its_password(): void
    {
        $temporary = $this->createAccount()['temporary_password'];
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', ['email' => 'nina@company.test', 'password' => $temporary])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Set your password on the web first, then sign in here.');
        $this->assertSame('must_change_password', AuditLog::query()->where('action', 'auth.login_failed')->sole()->after['reason']);

        // A wrong password still gets the generic message.
        $this->postJson('/api/v1/auth/login', ['email' => 'nina@company.test', 'password' => 'wrong-password'])
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
        $this->assertSame(0, User::query()->where('email', 'nina@company.test')->sole()->tokens()->count());
    }

    public function test_an_account_that_must_choose_its_password_reaches_nothing_else(): void
    {
        $this->createAccount(['role' => 'administrator']);
        $user = User::query()->where('email', 'nina@company.test')->sole();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/me/profile')->assertForbidden()->assertJsonPath('message', 'Set your password on the web first.');
        $this->getJson('/api/v1/users')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
        $this->get('/app')->assertRedirect(route('password.choose'));
        $this->get('/admin')->assertRedirect(route('password.choose'));
        $this->get('/password')->assertOk()->assertSee('Choose your password');
    }

    // ---- Change my password ------------------------------------------------

    public function test_choosing_a_password_clears_the_flag_and_lets_the_phone_sign_in(): void
    {
        $temporary = $this->createAccount()['temporary_password'];
        $user = User::query()->where('email', 'nina@company.test')->sole();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
        $this->putJson('/api/v1/me/password', [
            'current_password' => $temporary, 'password' => 'a-long-chosen-pass', 'password_confirmation' => 'a-long-chosen-pass',
        ])->assertOk()->assertJsonPath('data.message', 'Password changed.');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('a-long-chosen-pass', $user->password));
        $this->assertSame($user->public_id, AuditLog::query()->where('action', 'user.password_changed')->sole()->entity_public_id);
        $this->assertNotInAuditLog('a-long-chosen-pass');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'nina@company.test', 'password' => 'a-long-chosen-pass'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'nina@company.test', 'password' => $temporary])->assertUnprocessable();
    }

    public function test_a_new_password_follows_the_rules(): void
    {
        $user = User::factory()->staff()->create(['email' => 'kai@company.test', 'password' => 'the-current-password']);
        Sanctum::actingAs($user);
        $put = fn (array $body) => $this->putJson('/api/v1/me/password', $body + ['current_password' => 'the-current-password']);

        $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'a-long-chosen-pass', 'password_confirmation' => 'a-long-chosen-pass'])
            ->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'That isn\'t your current password.');
        $put(['password' => 'short', 'password_confirmation' => 'short'])
            ->assertJsonPath('errors.password.0', 'Use at least 12 characters.');
        $put(['password' => 'a-long-chosen-pass', 'password_confirmation' => 'something-else-1'])
            ->assertJsonPath('errors.password.0', 'The two new passwords don\'t match.');
        $put(['password' => 'KAI@company.test', 'password_confirmation' => 'KAI@company.test'])
            ->assertJsonPath('errors.password.0', 'Don\'t use your email address as your password.');
        $put(['password' => 'the-current-password', 'password_confirmation' => 'the-current-password'])
            ->assertJsonPath('errors.password.0', 'Choose a password different from your current one.');
        $put(['password' => str_repeat('x', 73), 'password_confirmation' => str_repeat('x', 73)])
            ->assertJsonPath('errors.password.0', 'Use at most 72 characters.');

        $this->assertTrue(Hash::check('the-current-password', $user->fresh()->password));
    }

    public function test_changing_my_password_on_the_phone_keeps_this_sign_in_and_ends_the_others(): void
    {
        $user = User::factory()->staff()->create(['password' => 'the-current-password']);
        $kept = $user->createToken('mobile')->plainTextToken;
        $user->createToken('mobile');

        $this->withToken($kept)->putJson('/api/v1/me/password', [
            'current_password' => 'the-current-password', 'password' => 'a-long-chosen-pass', 'password_confirmation' => 'a-long-chosen-pass',
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($kept)->getJson('/api/v1/auth/me')->assertOk();
    }

    // ---- Reset password ----------------------------------------------------

    public function test_a_reset_gives_a_new_temporary_password_and_ends_every_sign_in(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->staff()->create(['password' => 'their-own-password']);
        $user->createToken('mobile');
        DB::table('sessions')->insert(['id' => 'their-web-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        Sanctum::actingAs($this->admin);
        $data = $this->postJson("/api/v1/users/{$user->public_id}/reset-password")->assertOk()->json('data');

        $this->assertMatchesRegularExpression(self::TEMPORARY, $data['temporary_password']);
        $this->assertTrue($data['must_change_password']);
        $user->refresh();
        $this->assertTrue(Hash::check($data['temporary_password'], $user->password));
        $this->assertFalse(Hash::check('their-own-password', $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame($user->public_id, AuditLog::query()->where('action', 'user.password_reset')->sole()->entity_public_id);
        $this->assertNotInAuditLog($data['temporary_password']);
    }

    public function test_you_cannot_reset_or_suspend_your_own_account(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/users/{$this->admin->public_id}/reset-password")
            ->assertUnprocessable()->assertJsonPath('errors.user.0', 'To change your own password, use Change password on your profile.');
        $this->putJson("/api/v1/users/{$this->admin->public_id}/status", ['status' => 'suspended'])
            ->assertUnprocessable()->assertJsonPath('errors.status.0', 'You can\'t suspend or reactivate your own account.');

        $this->assertSame(AccountStatus::Active, $this->admin->fresh()->status);
        $this->assertFalse($this->admin->fresh()->must_change_password);
    }

    // ---- Suspend and reactivate -------------------------------------------

    public function test_suspending_ends_sign_ins_and_refuses_new_ones_until_reactivated(): void
    {
        $user = User::factory()->staff()->create(['email' => 'sam@company.test', 'password' => 'their-own-password']);
        $user->createToken('mobile');

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/users/{$user->public_id}/status", ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertSame(0, $user->tokens()->count());
        $entry = AuditLog::query()->where('action', 'user.status_changed')->sole();
        $this->assertSame(['status' => 'active'], $entry->before);
        $this->assertSame(['status' => 'suspended'], $entry->after);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'sam@company.test', 'password' => 'their-own-password'])
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/users/{$user->public_id}/status", ['status' => 'active'])->assertOk();
        $this->putJson("/api/v1/users/{$user->public_id}/status", ['status' => 'active'])->assertOk();
        $this->putJson("/api/v1/users/{$user->public_id}/status", ['status' => 'deleted'])->assertUnprocessable();
        $this->assertSame(2, AuditLog::query()->where('action', 'user.status_changed')->count());

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'sam@company.test', 'password' => 'their-own-password'])->assertOk();
    }
}
