<?php

namespace App\Http\Middleware;

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
 * Deliberately not applied to logout routes: revoking one's own
 * session/token is always allowed, regardless of account status.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isActive()) {
            return $next($request);
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
}
