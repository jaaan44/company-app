<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 (Security Audit, F-02): Sanctum mobile tokens now expire after
 * config('sanctum.expiration') minutes (30 days / 43,200 minutes) instead
 * of never — bounding the blast radius of a lost/stolen device. This uses
 * Sanctum's own built-in expiration check (Laravel\Sanctum\Guard::
 * isValidAccessToken(), which compares the token's created_at against
 * now()->subMinutes($expiration)) — no custom refresh-token architecture.
 * Deterministic via Laravel's travel()/travelTo() test helpers (Carbon
 * test-now, auto-reset after each test) rather than real-time waiting.
 */
class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_expiration_is_thirty_days(): void
    {
        $this->assertSame(43200, config('sanctum.expiration'));
    }

    public function test_a_freshly_issued_token_authenticates_successfully(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_a_token_just_under_the_configured_expiration_still_authenticates(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->travel(config('sanctum.expiration') - 60)->minutes();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_a_token_past_the_configured_expiration_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    /**
     * Logout must keep revoking the presented token outright, regardless
     * of the expiration window — the two mechanisms are independent.
     */
    public function test_logout_still_revokes_the_token_immediately_regardless_of_expiration_config(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Laravel\Auth\RequestGuard caches its resolved user for the
        // guard instance's lifetime; without this, the request below
        // would reuse the user resolved by the logout call above instead
        // of re-authenticating the (now-deleted) token — a testing
        // artifact only, mirroring AdminLoginTest's identical
        // forgetGuards() precedent for the 'web' SessionGuard.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    /**
     * Suspended/inactive-account enforcement (EnsureAccountIsActive) must
     * remain fully intact for a token that is otherwise still unexpired —
     * adding token expiration must never weaken, replace, or race the
     * existing account-status check.
     */
    public function test_suspended_account_loses_access_mid_session_even_with_an_unexpired_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->status = AccountStatus::Suspended;
        $user->save();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();

        $this->assertSame(0, $user->tokens()->count());
    }
}
