<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Clients (Phase 34B): the mobile list (Phase 29C) — active
 * clients from `GET /clients?status=active`, 25 per page, searchable by
 * name or code.
 */
class Clients extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

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
        $result = null;
        $query = trim($this->search);

        try {
            $result = $this->api()->get('/clients', ['status' => 'active', 'per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page), 'q' => $query]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading clients.', forbidden: "You don't have access to clients.");
        }

        return view('livewire.portal.clients', [
            'clients' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'query' => $query,
            'mayAdd' => $this->may('clients.create'),
        ]);
    }
}
