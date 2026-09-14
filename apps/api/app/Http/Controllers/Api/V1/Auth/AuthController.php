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
     * regardless of whether the email exists AND regardless of whether a
     * matched account is suspended/inactive — a distinguishable message
     * for "account exists but is deactivated" would itself disclose
     * account existence/status to anyone holding a correct password for
     * it (Phase 22, F-01). Login behavior never discloses which emails
     * are registered, nor their account status (05_SECURITY_MODEL.md).
     * Every outcome is still audited (Phase 21, DEC-044) — a failure
     * never records the attempted password, only (when the email matches
     * a real account) which account was targeted, the internal reason,
     * plus IP/user agent; the external response never varies with it.
     */
    public function login(ApiLoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check($request->string('password'), $user->password)) {
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::AUTH_LOGIN_FAILED,
                entityType: 'User',
                entityPublicId: $user?->public_id,
                actor: null,
                after: ['reason' => 'invalid_credentials'],
            );

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        // Deliberately the same generic message as invalid credentials
        // above (Phase 22, F-01) — a distinguishable "this account is
        // deactivated" message would itself disclose account
        // existence/status to anyone who has a correct password for it,
        // contradicting 05_SECURITY_MODEL.md's no-enumeration guarantee.
        // The specific reason is preserved only in the audit entry.
        if (! $user->isActive()) {
            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::AUTH_LOGIN_FAILED,
                entityType: 'User',
                entityPublicId: $user->public_id,
                actor: null,
                after: ['reason' => 'inactive_account'],
            );

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
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
