<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Tasks (Phase 34B): the mobile Tasks tab (Phase 29A) from
 * `GET /me/tasks` — Open or Done, 25 per page, newest due first as the
 * API orders them, each with its status and due label.
 */
class Tasks extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 25;

    #[Url(except: 'open')]
    public string $state = 'open';

    #[Url(except: 1)]
    public int $page = 1;

    public function showState(string $state): void
    {
        $this->state = $state === 'closed' ? 'closed' : 'open';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $this->noProfile = false;
        $state = $this->state === 'closed' ? 'closed' : 'open';
        $result = null;

        try {
            $result = $this->api()->get('/me/tasks', ['state' => $state, 'per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page)]);
            if (($result['data']['tasks'] ?? null) === null) {
                $this->noProfile = true;
            }
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your tasks.', forbidden: "You don't have access to your tasks.");
        }

        return view('livewire.portal.tasks', [
            'tasks' => $result['data']['tasks'] ?? [],
            'companyDate' => $result['data']['company_day']['date'] ?? null,
            'meta' => $result['meta'] ?? null,
            'currentState' => $state,
        ]);
    }
}
