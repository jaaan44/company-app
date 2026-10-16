<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Users and roles (Phase 35C, DEC-065 P-5): every sign-in account with its
 * role, searchable, 25 per page, and changing an account's role after an
 * in-app confirmation. PUT /api/v1/users/{id}/role refuses your own account
 * and the last Administrator, and audits each change.
 *
 * Phase 36A (DEC-066 M-3…M-5): New account, Reset password and
 * Suspend / Reactivate. A reset's temporary password is shown once, in
 * the response that made it; it is never public, so never in Livewire's
 * snapshot.
 */
class Users extends Component
{
    use CallsInternalApi;

    #[Url(as: 'q')]
    public string $search = '';

    public int $page = 1;

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    /** The account just reset (without its password). */
    public ?array $resetAccount = null;

    protected ?string $temporaryPassword = null;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function changeRole(string $publicId, string $role): void
    {
        $this->errorMessage = null;
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId)) {
            return;
        }
        try {
            $user = $this->api()->send('PUT', "/users/{$publicId}/role", json: ['role' => $role])['data'];
            $this->savedMessage = "{$user['name']} is now ".($user['role']['label'] ?? $role).'.';
            $this->toastNonce++;
        } catch (PortalApiException $e) {
            if ($e->status === 422) {
                $this->errorMessage = $e->firstErrorFor('role') ?? $e->getMessage();
            } elseif ($e->isNotFound()) {
                $this->errorMessage = 'That account no longer exists.';
            } else {
                $this->failed($e, 'Something went wrong changing the role.', 'Only Administrators can change roles.');
            }
        }
    }

    public function resetPassword(string $publicId): void
    {
        $this->errorMessage = null;
        $this->resetAccount = null;
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId)) {
            return;
        }
        try {
            $account = $this->api()->send('POST', "/users/{$publicId}/reset-password")['data'];
            $this->temporaryPassword = $account['temporary_password'];
            unset($account['temporary_password']);
            $this->resetAccount = $account;
        } catch (PortalApiException $e) {
            $this->accountFailure($e, 'user', 'Something went wrong resetting the password.');
        }
    }

    public function setStatus(string $publicId, string $status): void
    {
        $this->errorMessage = null;
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId)) {
            return;
        }
        try {
            $user = $this->api()->send('PUT', "/users/{$publicId}/status", json: ['status' => $status])['data'];
            $this->savedMessage = $user['status'] === 'active' ? "{$user['name']} can sign in again." : "{$user['name']} is suspended and signed out everywhere.";
            $this->toastNonce++;
        } catch (PortalApiException $e) {
            $this->accountFailure($e, 'status', 'Something went wrong changing the account.');
        }
    }

    public function dismissReset(): void
    {
        $this->resetAccount = null;
    }

    private function accountFailure(PortalApiException $e, string $field, string $fallback): void
    {
        if ($e->status === 422) {
            $this->errorMessage = $e->firstErrorFor($field) ?? $e->getMessage();
        } elseif ($e->isNotFound()) {
            $this->errorMessage = 'That account no longer exists.';
        } else {
            $this->failed($e, $fallback, 'Only Administrators can manage accounts.');
        }
    }

    public function render(): View
    {
        $result = null;
        try {
            $result = $this->api()->get('/users', array_filter(['q' => trim($this->search), 'page' => $this->page]));
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the accounts.', 'Only Administrators can change roles.');
        }

        return view('livewire.admin.users', [
            'users' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'temporaryPassword' => $this->temporaryPassword,
        ]);
    }
}
