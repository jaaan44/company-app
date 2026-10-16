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

        return view('livewire.admin.staff-record', [
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
