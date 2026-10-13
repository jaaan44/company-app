<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal "All status changes" / "All check-ins" (Phase 34C): the mobile
 * history lists (Phase 30B) — `GET /me/status` or `GET /me/check-ins`,
 * 20 per page, newest first.
 */
class StatusHistory extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 20;

    #[Locked]
    public string $kind = 'changes';

    #[Url(except: 1)]
    public int $page = 1;

    public function mount(string $kind = 'changes'): void
    {
        $this->kind = $kind === 'check-ins' ? 'check-ins' : 'changes';
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $result = null;

        try {
            $result = $this->api()->get($this->kind === 'check-ins' ? '/me/check-ins' : '/me/status', ['per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page)]);
        } catch (PortalApiException $e) {
            $this->failed($e, $this->kind === 'check-ins' ? 'Something went wrong loading your check-ins.' : 'Something went wrong loading your status changes.');
        }

        return view('livewire.portal.status-history', [
            'items' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'companyDay' => $result['meta']['company_day'] ?? null,
        ]);
    }
}
