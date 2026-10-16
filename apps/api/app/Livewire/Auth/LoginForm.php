<?php

namespace App\Livewire\Auth;

use App\Enums\AuditSource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The web login (Phase 4; shared since Phase 34A, DEC-063 W-3/W-4): every
 * active account signs in here and lands on the Staff Web Portal; the
 * Admin Backoffice stays behind `can:admin.access` on its own routes.
 * Session/cookie authentication via Laravel's `web` guard — see DEC-022. Credential failures always return
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

    /** Phase 37 (DEC-067 H-2): "Keep me signed in on this device". */
    public bool $remember = false;

    /** How long "Keep me signed in" lasts: 30 days, in minutes. */
    public const REMEMBER_MINUTES = 60 * 24 * 30;

    /**
     * Every outcome is audited (Phase 21, DEC-044), mirroring the mobile
     * API's identical AuthController::login() behavior — a failure never
     * records the attempted password, only (when the email matches a
     * real account) which account was targeted, plus IP/user agent.
     * `source: Web` distinguishes these entries from the API's own.
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

        $guard = Auth::guard('web');
        if ($guard instanceof SessionGuard) {
            $guard->setRememberDuration(self::REMEMBER_MINUTES);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
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

        session()->regenerate();

        $auditLogger->recordForRequest(
            request(),
            AuditActions::AUTH_LOGIN_SUCCEEDED,
            entityType: 'User',
            entityPublicId: $user->public_id,
            source: AuditSource::Web,
            actor: $user,
        );

        // Phase 36A (DEC-066 M-4): a new or reset account chooses its own
        // password before anything else.
        // Phase 37 (DEC-067 H-3): back to the page the person was on when
        // their session ended, if there was one.
        if ($user->must_change_password) {
            $this->redirect(route('password.choose'), navigate: false);
        } else {
            $this->redirectIntended(route('portal.home'), navigate: false);
        }
    }

    private function auditLoginFailed(AuditLogger $auditLogger, ?User $user, ?string $reason = null): void
    {
        $auditLogger->recordForRequest(
            request(),
            AuditActions::AUTH_LOGIN_FAILED,
            entityType: 'User',
            entityPublicId: $user?->public_id,
            source: AuditSource::Web,
            actor: null,
            after: $reason === null ? [] : ['reason' => $reason],
        );
    }

    public function render()
    {
        return view('livewire.auth.login-form');
    }
}
