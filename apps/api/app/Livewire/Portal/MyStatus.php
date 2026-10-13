<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Portal\Format;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Portal My status (Phase 34C): the mobile My status screen (Phase 30B)
 * — my current status and its 5 newest changes (`GET /me/status`), my 5
 * newest check-ins (`GET /me/check-ins`), and a confirmed status change
 * (`POST /me/status`). There is no browser check-in (DEC-063 W-7).
 */
class MyStatus extends Component
{
    use CallsInternalApi;

    public const RECENT = 5;

    public const STATUSES = ['available', 'busy', 'in_meeting', 'in_field', 'off_duty'];

    public ?string $savedMessage = null;

    public ?string $saveMessage = null;

    public function setStatus(string $status): void
    {
        $this->savedMessage = null;
        $this->saveMessage = null;
        if (! in_array($status, self::STATUSES, true)) {
            return;
        }

        try {
            $entry = $this->api()->send('POST', '/me/status', json: ['status' => $status])['data'] ?? [];
            $this->savedMessage = 'Status set to '.Format::operationalStatus($entry['status'] ?? $status).'.';
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired() || $e->isForbidden()) {
                $this->failed($e, '');

                return;
            }
            $this->saveMessage = "Couldn't change your status. Please try again.";
        }
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $statuses = null;
        $checkIns = null;

        if (! $this->noProfile) {
            try {
                $statuses = $this->api()->get('/me/status', ['per_page' => self::RECENT, 'page' => 1]);
                $checkIns = $this->api()->get('/me/check-ins', ['per_page' => self::RECENT, 'page' => 1]);
            } catch (PortalApiException $e) {
                $this->failed($e, 'Something went wrong loading your status.');
            }
        }

        return view('livewire.portal.my-status', [
            'statuses' => $statuses['data'] ?? [],
            'checkIns' => $checkIns['data'] ?? [],
            'companyDay' => $statuses['meta']['company_day'] ?? null,
        ]);
    }
}
