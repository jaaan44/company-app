<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Projects (Phase 34B): the mobile list (Phase 29C, R-20) — the
 * projects **I am a member of**, for every role, from
 * `GET /projects?member=<my staff id>`, 25 per page, searchable by name
 * or code.
 */
class Projects extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 25;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 1)]
    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function clearSearch(): void
    {
        $this->search = '';
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
        $result = null;
        $query = trim($this->search);

        try {
            $staffId = $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? null;
            if ($staffId === null) {
                $this->noProfile = true;
            } else {
                $result = $this->api()->get('/projects', ['member' => $staffId, 'per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page), 'q' => $query]);
            }
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your projects.', forbidden: "You don't have access to projects.");
        }

        return view('livewire.portal.projects', [
            'projects' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'query' => $query,
        ]);
    }
}
