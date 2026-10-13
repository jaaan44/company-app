<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Messages (Phase 34D): the mobile Messages tab (Phase 32, R-3) —
 * my conversations from `GET /conversations`, newest activity first, 20
 * per page, each with its name, last message, time and unread count;
 * **New message** starts a direct chat.
 */
class Messages extends Component
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
        $myStaffId = null;

        try {
            $myStaffId = $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? null;
            if ($myStaffId === null) {
                $this->noProfile = true;
            } else {
                $result = $this->api()->get('/conversations', ['per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page)]);
            }
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your conversations.');
        }

        return view('livewire.portal.messages', [
            'conversations' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'companyDay' => $result['meta']['company_day'] ?? null,
            'myStaffId' => $myStaffId,
        ]);
    }
}
