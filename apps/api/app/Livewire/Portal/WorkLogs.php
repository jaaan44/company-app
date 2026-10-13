<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal "My work logs" (Phase 34B): the mobile list (Phase 29B) from
 * `GET /me/work-logs` — newest first, grouped by date ("Today",
 * "Yesterday", then the date) against the API's company day.
 */
class WorkLogs extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 25;

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
            $result = $this->api()->get('/me/work-logs', ['per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page)]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your work logs.');
        }

        $days = [];
        foreach ($result['data'] ?? [] as $log) {
            $days[$log['work_date']][] = $log;
        }

        return view('livewire.portal.work-logs', [
            'days' => $days,
            'companyDate' => $result['meta']['company_day']['date'] ?? null,
            'meta' => $result['meta'] ?? null,
        ]);
    }
}
