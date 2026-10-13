<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal announcement (Phase 34D): the mobile detail (Phase 32, R-7) —
 * `GET /me/announcements/{id}`: the title, published by and when, the
 * full body as plain text, and **Acknowledge** (`POST …/acknowledge`)
 * until acknowledged, then "Acknowledged on …" from the API.
 */
class AnnouncementShow extends Component
{
    use CallsInternalApi;

    #[Locked]
    public string $publicId = '';

    public ?string $actionMessage = null;

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function acknowledge(): void
    {
        $this->actionMessage = null;

        try {
            $this->api()->send('POST', $this->path().'/acknowledge');
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            $this->actionMessage = $e->isNotFound() ? 'This announcement is no longer available.' : "Couldn't acknowledge it. Please try again.";
        }
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $announcement = null;
        $companyDay = null;

        try {
            $announcement = $this->api()->get($this->path())['data'] ?? null;
            $companyDay = $this->api()->get('/me/announcements', ['per_page' => 1])['meta']['company_day'] ?? null;
        } catch (PortalApiException $e) {
            if ($announcement === null) {
                if ($e->isNotFound()) {
                    $this->errorMessage = 'This announcement is no longer available.';
                } else {
                    $this->failed($e, 'Something went wrong loading this announcement.');
                }
            } elseif ($e->isSessionExpired()) {
                $announcement = null;
                $this->failed($e, '');
            }
        }

        return view('livewire.portal.announcement-show', ['announcement' => $announcement, 'companyDay' => $companyDay]);
    }

    private function path(): string
    {
        return '/me/announcements/'.rawurlencode($this->publicId);
    }
}
