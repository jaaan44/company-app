<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_return_a_token_and_safe_user_data(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'user' => ['public_id', 'name', 'email', 'status'],
                    'token',
                ],
            ])
            ->assertJsonMissingPath('data.user.id')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token')
            ->assertJsonMissingPath('data.user.is_admin');

        $this->assertSame($user->public_id, $response->json('data.user.public_id'));
    }

    public function test_invalid_password_is_rejected_with_a_generic_message(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_unknown_email_is_rejected_with_the_same_generic_message_as_wrong_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertUnprocessable();

        $this->assertSame(
            'These credentials do not match our records.',
            $response->json('errors.email.0'),
        );
    }

    public function test_suspended_account_cannot_log_in(): void
    {
        $user = User::factory()->suspended()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
    }

    /**
     * Phase 22, F-01: a suspended/inactive account authenticated with the
     * *correct* password must receive a response byte-identical to a
     * wrong-password attempt against an active account — otherwise the
     * response itself discloses that the account exists and is
     * deactivated, contradicting 05_SECURITY_MODEL.md's no-enumeration
     * guarantee.
     */
    public function test_suspended_account_with_correct_password_gets_the_same_response_as_wrong_password(): void
    {
        $suspended = User::factory()->suspended()->create([
            'password' => Hash::make('correct-password'),
        ]);
        $active = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $suspendedResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $suspended->email,
            'password' => 'correct-password',
        ]);

        $wrongPasswordResponse = $this->postJson('/api/v1/auth/login', [
            'email' => $active->email,
            'password' => 'wrong-password',
        ]);

        $suspendedResponse->assertUnprocessable();
        $wrongPasswordResponse->assertUnprocessable();

        $this->assertSame(
            $wrongPasswordResponse->json('errors.email.0'),
            $suspendedResponse->json('errors.email.0'),
        );
        $this->assertSame(
            'These credentials do not match our records.',
            $suspendedResponse->json('errors.email.0'),
        );
        $this->assertGuest();
    }

    public function test_inactive_account_with_correct_password_gets_the_same_response_as_wrong_password(): void
    {
        $inactive = User::factory()->inactive()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $inactive->email,
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(
            'These credentials do not match our records.',
            $response->json('errors.email.0'),
        );
        $this->assertGuest();
    }

    /**
     * Confirms the F-01 message unification never allows a
     * suspended/inactive account to actually authenticate — the fix
     * changes only the disclosed message, never the enforcement.
     */
    public function test_suspended_account_never_receives_a_token_regardless_of_message_unification(): void
    {
        $user = User::factory()->suspended()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable()->assertJsonMissingPath('data.token');
        $this->assertGuest();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_email_and_password_are_required(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_no_public_registration_endpoint_exists(): void
    {
        $this->post('/register')->assertNotFound();
        $this->postJson('/api/v1/auth/register')->assertNotFound();
    }
}
