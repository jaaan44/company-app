<?php

namespace App\Livewire\Auth;

use App\Enums\AuditSource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Admin Backoffice login (Phase 4). Session/cookie authentication via
 * Laravel's `web` guard — see DEC-022. Credential failures always return
 * the same generic message so login behavior never discloses which
 * emails are registered, nor whether a matched account is
 * suspended/inactive (Phase 22, F-01 — see 05_SECURITY_MODEL.md).
 *
 * Rate limiting is enforced here directly (rather than via route
 * middleware) because the actual login submission is an internal
 * Livewire AJAX request, not a direct POST to a throttled route — see
 * CLAUDE.md §19 / docs/05_SECURITY_MODEL.md.
 */
class LoginForm extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    /**
     * Every outcome is audited (Phase 21, DEC-044), mirroring the mobile
     * API's identical AuthController::login() behavior — a failure never
     * records the attempted password, only (when the email matches a
     * real account) which account was targeted, plus IP/user agent.
     * `source: Admin` distinguishes these entries from the API's own.
     */
    public function login(): void
    {
        $this->validate();

        $auditLogger = app(AuditLogger::class);
        $key = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($key, 60);

            $this->auditLoginFailed($auditLogger, User::where('email', $this->email)->first(), 'invalid_credentials');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);

        $user = Auth::user();

        // Deliberately the same generic message as invalid credentials
        // above (Phase 22, F-01) — a distinguishable "this account is
        // deactivated" message would itself disclose account
        // existence/status to anyone who has a correct password for it,
        // contradicting 05_SECURITY_MODEL.md's no-enumeration guarantee.
        // The specific reason is preserved only in the audit entry.
        if (! $user->isActive()) {
            Auth::logout();

            $this->auditLoginFailed($auditLogger, $user, 'inactive_account');

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->cannot('admin.access')) {
            Auth::logout();

            $this->auditLoginFailed($auditLogger, $user);

            throw ValidationException::withMessages([
                'email' => 'This account does not have Admin Backoffice access.',
            ]);
        }

        session()->regenerate();

        $auditLogger->recordForRequest(
            request(),
            AuditActions::AUTH_LOGIN_SUCCEEDED,
            entityType: 'User',
            entityPublicId: $user->public_id,
            source: AuditSource::Admin,
            actor: $user,
        );

        $this->redirect(route('home'), navigate: false);
    }

    private function auditLoginFailed(AuditLogger $auditLogger, ?User $user, ?string $reason = null): void
    {
        $auditLogger->recordForRequest(
            request(),
            AuditActions::AUTH_LOGIN_FAILED,
            entityType: 'User',
            entityPublicId: $user?->public_id,
            source: AuditSource::Admin,
            actor: null,
            after: $reason === null ? [] : ['reason' => $reason],
        );
    }

    public function render()
    {
        return view('livewire.auth.login-form');
    }
}
