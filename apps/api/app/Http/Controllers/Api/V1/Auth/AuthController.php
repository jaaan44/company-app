<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ApiLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
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
    /**
     * POST /api/v1/auth/login — issue a Sanctum personal access token.
     *
     * Credential failures always return the same generic message
     * regardless of whether the email exists, so login behavior never
     * discloses which emails are registered (05_SECURITY_MODEL.md).
     */
    public function login(ApiLoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['This account is not currently active. Contact an administrator.'],
            ]);
        }

        $token = $user->createToken('mobile')->plainTextToken;

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
     * multi-device use.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

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
