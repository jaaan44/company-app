<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Announcements (Phase 34D): the mobile feed (Phase 32, R-7) —
 * `GET /me/announcements`, newest first, 20 per page: the title (bold
 * until acknowledged), the published date, and "New" until acknowledged.
 */
class Announcements extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 20;

    #[Url(except: 1)]
    public int $page = 1;

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $this->noProfile = false;
        $result = null;

        try {
            $result = $this->api()->get('/me/announcements', ['per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page)]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading announcements.');
        }

        return view('livewire.portal.announcements', [
            'announcements' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'companyDay' => $result['meta']['company_day'] ?? null,
        ]);
    }
}
