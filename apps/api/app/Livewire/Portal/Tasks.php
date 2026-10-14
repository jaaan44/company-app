<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
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
    use WritesThroughApi;

    public const PAGE_SIZE = 25;

    #[Url(except: 'open')]
    public string $state = 'open';

    #[Url(except: 1)]
    public int $page = 1;

    /** Phase 35D (D-4): "mine" (my tasks) or "all" (every task I may see), for holders of tasks.view. */
    #[Url(except: 'mine')]
    public string $scope = 'mine';

    #[Url(except: '')]
    public string $search = '';

    public function showScope(string $scope): void
    {
        $this->scope = $scope === 'all' ? 'all' : 'mine';
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->page = 1;
    }

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

        $mayViewAll = $this->may('tasks.view');
        $scope = $mayViewAll && $this->scope === 'all' ? 'all' : 'mine';
        $tasks = [];
        $companyDate = null;

        try {
            $result = $this->api()->get('/me/tasks', ['state' => $state, 'per_page' => $scope === 'all' ? 1 : self::PAGE_SIZE, 'page' => $scope === 'all' ? 1 : max(1, $this->page)]);
            $companyDate = $result['data']['company_day']['date'] ?? null;
            if (($result['data']['tasks'] ?? null) === null) {
                $this->noProfile = $scope === 'mine';
            }
            if ($scope === 'all') {
                $result = $this->api()->get('/tasks', array_filter(['q' => trim($this->search), 'per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page)]));
                $tasks = $result['data'] ?? [];
            } else {
                $tasks = $result['data']['tasks'] ?? [];
            }
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your tasks.', forbidden: "You don't have access to your tasks.");
        }

        return view('livewire.portal.tasks', [
            'tasks' => $tasks,
            'companyDate' => $companyDate,
            'meta' => $result['meta'] ?? null,
            'currentState' => $state,
            'currentScope' => $scope,
            'mayViewAll' => $mayViewAll,
            'mayAdd' => $this->mayAddTasks(),
        ]);
    }

    /** D-3: holders of tasks.create, and project leads (for their own projects). */
    private function mayAddTasks(): bool
    {
        if ($this->may('tasks.create')) {
            return true;
        }
        try {
            $staffId = $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? null;
            if ($staffId === null) {
                return false;
            }
            $projects = $this->api()->get('/projects', ['member' => $staffId, 'per_page' => 100])['data'] ?? [];

            return collect($projects)->contains(fn ($p) => ($p['my_role'] ?? null) === 'project_lead'
                && ! in_array($p['status'], ['completed', 'cancelled'], true));
        } catch (PortalApiException) {
            return false;
        }
    }
}
