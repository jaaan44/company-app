<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Staff directory (Phase 34A): the mobile directory (Phase 28) —
 * active staff only, 25 per page, a name search — from `GET /staff`
 * with the same query the app sends. The search and page live in the
 * URL, so a result can be bookmarked and Back works.
 */
class Directory extends Component
{
    use CallsInternalApi;

    /** The mobile app's page size (people_api_client.dart). */
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
        $search = trim($this->search);

        try {
            $result = $this->api()->get('/staff', [
                'status' => 'active',
                'per_page' => self::PAGE_SIZE,
                'page' => max(1, $this->page),
                'q' => $search,
            ]);
        } catch (PortalApiException $e) {
            $this->failed(
                $e,
                'Something went wrong loading the staff directory.',
                forbidden: 'You don\'t have access to the staff directory.',
            );
        }

        return view('livewire.portal.directory', [
            'staff' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'query' => $search,
            // Phase 37D: each person's status, for those who may see it (staff-status.view).
            'mayViewStatus' => Auth::user()?->can('staff-status.view') ?? false,
        ]);
    }
}
