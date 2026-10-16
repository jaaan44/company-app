<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Centralized account-state enforcement for already-authenticated access
 * (CLAUDE.md §7 — "implement this centrally rather than scattering status
 * checks throughout future controllers"). A suspended/inactive account
 * loses access even with a still-valid session or token.
 *
 * Phase 36A (DEC-066 M-4): an active account that must change its
 * password reaches only "Choose your password" (web) and PUT
 * /api/v1/me/password; everything else is refused or redirected there.
 *
 * Deliberately not applied to logout routes: revoking one's own
 * session/token is always allowed, regardless of account status.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($user->isActive()) {
            return $this->mustChangePassword($request, $user) ? $this->toChoosePassword($request) : $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            try {
                // A real Sanctum personal access token is revocable; a
                // session ('web' guard) request wrapped in a
                // TransientToken has no delete() method to call, and
                // there may be no token at all — both are caught below.
                $user->currentAccessToken()->delete();
            } catch (Throwable) {
                // Nothing to revoke for a TransientToken or no token.
            }

            abort(403, 'This account is not currently active.');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => 'Your account is not currently active. Contact an administrator.',
        ]);
    }

    /** Routes an account that must change its password may still use. */
    private const ALLOWED_WHILE_CHANGING = ['password.choose', 'api.v1.me.password.update'];

    private function mustChangePassword(Request $request, User $user): bool
    {
        return $user->must_change_password && ! in_array($request->route()?->getName(), self::ALLOWED_WHILE_CHANGING, true);
    }

    private function toChoosePassword(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            abort(403, 'Set your password on the web first.');
        }

        return redirect()->route('password.choose');
    }
}
