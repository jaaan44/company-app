<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A staff record (Phase 36A): the details, with Edit (`staff.update`) and
 * Delete (`staff.delete`); the sign-in account (`authorization.manage`);
 * the current status and correcting it (`staff-status.view` /
 * `staff-status.manage`); and check-ins, with Remove (`location.view` /
 * `location.manage`). Each part is the API's; this page only decides what
 * to show, and shows the API's messages.
 */
class StaffRecord extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    #[Locked]
    public string $publicId;

    #[Locked]
    public bool $notFound = false;

    public string $newStatus = 'available';

    public int $checkInPage = 1;

    /** Phase 37: search for an existing sign-in account to link. */
    public string $accountSearch = '';

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function goToPage(int $page): void
    {
        $this->checkInPage = max(1, $page);
    }

    public function setStatus(): void
    {
        $this->generalError = null;
        try {
            $this->api()->send('POST', '/staff/'.rawurlencode($this->publicId).'/status', json: ['status' => $this->newStatus]);
            $this->toast('Status changed.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't change the status. Please try again.");
        }
    }

    public function removeCheckIn(string $checkInId): void
    {
        $this->generalError = null;
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $checkInId)) {
            return;
        }
        try {
            $this->api()->send('DELETE', '/check-ins/'.$checkInId);
            $this->toast('Check-in removed.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't remove the check-in. Please try again.");
        }
    }

    /**
     * Phase 37 (DEC-067, from 36A): link an existing sign-in account that
     * no staff record has yet, through PATCH /api/v1/staff/{id} `user_id`.
     */
    public function linkAccount(string $userId): void
    {
        $this->generalError = null;
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $userId)) {
            return;
        }
        try {
            $this->api()->send('PATCH', '/staff/'.rawurlencode($this->publicId), json: ['user_id' => $userId]);
            $this->accountSearch = '';
            $this->toast('Sign-in account linked.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't link the account. Please try again.");
        }
    }

    public function unlinkAccount(): void
    {
        $this->generalError = null;
        try {
            $this->api()->send('PATCH', '/staff/'.rawurlencode($this->publicId), json: ['user_id' => null]);
            $this->toast('Sign-in account unlinked.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't unlink the account. Please try again.");
        }
    }

    public function delete(): void
    {
        $this->generalError = null;
        try {
            $this->api()->send('DELETE', '/staff/'.rawurlencode($this->publicId));
            session()->flash('status', 'Staff record deleted.');
            $this->redirect(route('admin.staff'), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't delete the staff record. Please try again.");
            if ($e->status === 409) {
                $this->generalError .= ' To keep the history, set their status to Separated instead.';
            }
        }
    }

    private function toast(string $message): void
    {
        $this->savedMessage = $message;
        $this->toastNonce++;
    }

    public function render(): View
    {
        $staff = null;
        $statuses = null;
        $checkIns = null;
        try {
            $staff = $this->api()->get('/staff/'.rawurlencode($this->publicId))['data'];
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading the staff record.', "You don't have access to staff records.");
            }
        }

        if ($staff !== null && $this->may('staff-status.view')) {
            try {
                $statuses = $this->api()->get('/staff/'.rawurlencode($this->publicId).'/status', ['per_page' => 5]);
            } catch (PortalApiException) {
                $statuses = null;
            }
        }
        if ($staff !== null && $this->may('location.view')) {
            try {
                $checkIns = $this->api()->get('/staff/'.rawurlencode($this->publicId).'/check-ins', ['per_page' => 10, 'page' => $this->checkInPage]);
            } catch (PortalApiException) {
                $checkIns = null;
            }
        }

        $accounts = [];
        if ($staff !== null && ($staff['user'] ?? null) === null && $this->may('authorization.manage') && mb_strlen(trim($this->accountSearch)) >= 2) {
            try {
                $accounts = array_values(array_filter(
                    $this->api()->get('/users', ['q' => trim($this->accountSearch)])['data'] ?? [],
                    fn (array $account) => $account['staff'] === null,
                ));
            } catch (PortalApiException) {
                $accounts = [];
            }
        }

        return view('livewire.admin.staff-record', [
            'accounts' => $accounts,
            'staff' => $staff,
            'statuses' => $statuses,
            'checkIns' => $checkIns,
            // These two lists carry no company day, so times are shown in
            // the company's timezone directly.
            'timezone' => CompanyTimezone::value(),
            'can' => [
                'update' => $this->may('staff.update'),
                'delete' => $this->may('staff.delete'),
                'accounts' => $this->may('authorization.manage'),
                'setStatus' => $this->may('staff-status.manage'),
                'removeCheckIn' => $this->may('location.manage'),
            ],
        ]);
    }
}
