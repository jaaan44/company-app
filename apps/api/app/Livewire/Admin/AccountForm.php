<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New sign-in account (Phase 36A, DEC-066 M-3/M-5), through POST
 * /api/v1/users: name, email, role, and optionally the staff record it
 * belongs to. The temporary password the API returns is shown once, in
 * the response that created the account. It is never put in the
 * session, a URL or Livewire's snapshot.
 */
class AccountForm extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    public string $name = '';

    public string $email = '';

    public string $role = 'staff';

    public string $staffId = '';

    public string $staffName = '';

    public string $staffSearch = '';

    /** The new account (without its password), once created. */
    #[Locked]
    public ?array $created = null;

    /**
     * Not public, so it never enters Livewire's page snapshot: it is
     * rendered in the one response that created the account.
     */
    protected ?string $temporaryPassword = null;

    public function mount(?string $staff = null): void
    {
        if ($staff === null || ! preg_match('/^[0-9A-Za-z]{1,64}$/', $staff)) {
            return;
        }
        try {
            $s = $this->api()->get('/staff/'.$staff)['data'];
        } catch (PortalApiException) {
            return;
        }
        if (! ($s['has_user_account'] ?? false)) {
            $this->chooseStaff($s['public_id'], $s['display_name']);
            $this->name = trim($s['first_name'].' '.$s['last_name']);
            $this->email = (string) ($s['company_email'] ?? '');
        }
    }

    public function chooseStaff(string $publicId, string $name): void
    {
        $this->staffId = $publicId;
        $this->staffName = $name;
        $this->staffSearch = '';
    }

    public function clearStaff(): void
    {
        $this->staffId = '';
        $this->staffName = '';
    }

    public function save(): void
    {
        if ($this->created !== null) {
            return;
        }
        $this->generalError = null;
        $this->resetErrorBag();

        $name = trim($this->name);
        $email = trim($this->email);
        if ($name === '') {
            $this->addError('name', 'Give the person\'s name.');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError('email', 'Enter a valid email address. They sign in with it.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $account = $this->api()->send('POST', '/users', json: [
                'name' => $name,
                'email' => $email,
                'role' => in_array($this->role, ['administrator', 'manager', 'staff'], true) ? $this->role : 'staff',
                'staff_id' => $this->staffId === '' ? null : $this->staffId,
            ])['data'];
            $this->temporaryPassword = $account['temporary_password'];
            unset($account['temporary_password']);
            $this->created = $account;
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, ['name' => 'name', 'email' => 'email', 'role' => 'role', 'staff_id' => 'staffSearch'], "Couldn't create the account. Please try again.");
        }
    }

    public function render(): View
    {
        $people = [];
        if ($this->created === null && mb_strlen(trim($this->staffSearch)) >= 2) {
            try {
                $people = array_values(array_filter(
                    $this->api()->get('/staff', ['q' => trim($this->staffSearch), 'per_page' => 8])['data'] ?? [],
                    fn (array $person) => ! ($person['has_user_account'] ?? false),
                ));
            } catch (PortalApiException) {
                $people = [];
            }
        }

        return view('livewire.admin.account-form', ['people' => $people, 'temporaryPassword' => $this->temporaryPassword]);
    }
}
