<?php

namespace App\Livewire\Auth;

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
 * emails are registered (05_SECURITY_MODEL.md).
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

    public function login(): void
    {
        $this->validate();

        $key = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($key);

        $user = Auth::user();

        if (! $user->isActive()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account is not currently active. Contact an administrator.',
            ]);
        }

        if (! $user->is_admin) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account does not have Admin Backoffice access.',
            ]);
        }

        session()->regenerate();

        $this->redirect(route('home'), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.login-form');
    }
}
