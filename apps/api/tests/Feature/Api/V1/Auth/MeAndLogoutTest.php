<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeAndLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_their_own_identity(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'public_id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ])
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.password');
    }

    public function test_unauthenticated_request_to_me_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile');

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_revoked_token_cannot_subsequently_authenticate(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Laravel's RequestGuard caches its resolved user for the lifetime
        // of the guard instance; without this, the second call below would
        // reuse the guard resolved during the logout call above instead of
        // re-authenticating against the (now token-less) database state —
        // a testing artifact only, not a production behavior.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_logout_only_revokes_the_current_token_not_other_devices(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $tokenB = $user->createToken('device-b');

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $tokenB->accessToken->id,
        ]);
    }

    public function test_an_account_suspended_after_token_issuance_loses_access(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        // Direct attribute assignment (not update()) since 'status' is
        // deliberately not mass-assignable.
        $user->status = AccountStatus::Suspended;
        $user->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me');

        $response->assertForbidden();
    }
}
