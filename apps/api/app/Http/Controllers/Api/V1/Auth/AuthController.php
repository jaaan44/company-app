<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ApiLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Mobile API authentication (Phase 4). Sanctum personal access tokens —
 * bearer-token authentication, no cookie/SPA session state. See DEC-022.
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * POST /api/v1/auth/login — issue a Sanctum personal access token.
     *
     * Credential failures always return the same generic message
     * regardless of whether the email exists, so login behavior never
     * discloses which emails are registered (05_SECURITY_MODEL.md).
     * Every outcome is audited (Phase 21, DEC-044) — a failure never
     * records the attempted password, only (when the email matches a
     * real account) which account was targeted, plus IP/user agent.
     */
    public function login(ApiLoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::AUTH_LOGIN_FAILED,
                entityType: 'User',
                entityPublicId: $user?->public_id,
                actor: null,
            );

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->isActive()) {
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::AUTH_LOGIN_FAILED,
                entityType: 'User',
                entityPublicId: $user->public_id,
                actor: null,
            );

            throw ValidationException::withMessages([
                'email' => ['This account is not currently active. Contact an administrator.'],
            ]);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::AUTH_LOGIN_SUCCEEDED,
            entityType: 'User',
            entityPublicId: $user->public_id,
            actor: $user,
        );

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/logout — revoke only the token used for this
     * request, leaving other devices' tokens intact for future
     * multi-device use. The actor is captured before the token is
     * revoked (Phase 21, DEC-044) so the audit entry never loses its own
     * actor identity to the very action it's recording.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::AUTH_LOGOUT,
            entityType: 'User',
            entityPublicId: $user->public_id,
            actor: $user,
        );

        $user->currentAccessToken()->delete();

        return response()->json([
            'data' => ['message' => 'Logged out successfully.'],
        ]);
    }

    /**
     * GET /api/v1/auth/me — the authenticated user's safe identity info,
     * used by Flutter to restore state / validate a stored token.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
