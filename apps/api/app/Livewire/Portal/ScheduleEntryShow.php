<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal schedule entry (Phase 34B): the mobile entry page (Phase 30A)
 * from `GET /schedule-entries/{id}` — when, project, organizer, people
 * and description, in company time (the offset from `/me/schedule`).
 */
class ScheduleEntryShow extends Component
{
    use CallsInternalApi;

    #[Locked]
    public string $publicId = '';

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $entry = null;
        $offset = null;

        try {
            $entry = $this->api()->get('/schedule-entries/'.rawurlencode($this->publicId))['data'] ?? null;
            $probe = CarbonImmutable::now('UTC')->toDateString();
            $offset = $this->api()->get('/me/schedule', ['from' => $probe, 'to' => $probe])['data']['company_day']['utc_offset'] ?? null;
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $entry = null;
                $this->failed($e, '');
            } elseif ($entry === null) {
                if ($e->isNotFound()) {
                    $this->errorMessage = 'This item is no longer available.';
                } else {
                    $this->failed($e, 'Something went wrong loading this item.', forbidden: "You don't have access to this item.");
                }
            }
        }

        return view('livewire.portal.schedule-entry-show', ['entry' => $entry, 'offset' => $offset ?? '+00:00']);
    }
}
