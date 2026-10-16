<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Auth\Passwords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * PUT /api/v1/me/password (Phase 36A, DEC-066 M-4/M-8): anyone changes
 * their own password, given the current one. It clears "must change
 * password" and ends the account's other phone sign-ins; the sign-in in
 * use stays, and so does this web session, but every "Keep me signed in"
 * cookie stops working (Phase 37). Audited without the password.
 */
class MyPasswordController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => Passwords::rules($user),
        ], [
            'current_password.required' => 'Enter your current password.',
            'password.required' => 'Choose a new password.',
            'password.min' => 'Use at least '.Passwords::MIN_LENGTH.' characters.',
            'password.confirmed' => 'The two new passwords don\'t match.',
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That isn\'t your current password.']);
        }

        $user->password = $data['password'];
        $user->must_change_password = false;
        // Phase 37 (DEC-067 H-2): "Keep me signed in" on other devices ends.
        $user->setRememberToken(Str::random(60));
        $user->save();

        // A phone request keeps the token it used; a web request has none.
        $bearer = $request->bearerToken();
        $current = $bearer === null ? null : PersonalAccessToken::findToken($bearer);
        $user->tokens()->when($current !== null, fn ($query) => $query->whereKeyNot($current?->getKey()))->delete();

        $this->auditLogger->recordForRequest($request, AuditActions::USER_PASSWORD_CHANGED, 'user', $user->public_id, actor: $user);

        return response()->json(['data' => ['message' => 'Password changed.']]);
    }
}
