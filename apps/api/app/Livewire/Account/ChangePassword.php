<?php

namespace App\Livewire\Account;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use App\Support\Auth\Passwords;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Change my password (Phase 36A, DEC-066 M-4/M-8), through PUT
 * /api/v1/me/password. `forced` is the first sign-in after an
 * Administrator created or reset the account ("Choose your password"):
 * the current password is the temporary one, and nothing else opens
 * until this succeeds. The API applies every rule.
 */
class ChangePassword extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    #[Locked]
    public bool $forced = false;

    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();

        if ($this->currentPassword === '') {
            $this->addError('currentPassword', $this->forced ? 'Enter the temporary password you were given.' : 'Enter your current password.');
        }
        if (mb_strlen($this->password) < Passwords::MIN_LENGTH) {
            $this->addError('password', 'Use at least '.Passwords::MIN_LENGTH.' characters.');
        } elseif ($this->password !== $this->passwordConfirmation) {
            $this->addError('passwordConfirmation', 'The two new passwords don\'t match.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $this->api()->send('PUT', '/me/password', json: [
                'current_password' => $this->currentPassword,
                'password' => $this->password,
                'password_confirmation' => $this->passwordConfirmation,
            ]);
        } catch (PortalApiException $e) {
            $this->reset('currentPassword', 'password', 'passwordConfirmation');
            if ($e->status === 429) {
                $this->generalError = 'Too many attempts. Wait a minute and try again.';

                return;
            }
            $this->applyWriteFailure($e, [
                'current_password' => 'currentPassword',
                'password' => 'password',
            ], "Couldn't change the password. Please try again.");

            return;
        }

        session()->flash('status', $this->forced ? 'Your password is set.' : 'Password changed.');
        $this->redirect(route($this->forced ? 'portal.home' : 'portal.profile'), navigate: ! $this->forced);
    }

    public function render(): View
    {
        return view('livewire.account.change-password', ['minLength' => Passwords::MIN_LENGTH]);
    }
}
