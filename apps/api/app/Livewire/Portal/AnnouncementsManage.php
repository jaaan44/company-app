<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Announcements → Manage (Phase 35D, D-4/D-7): every announcement, by
 * status, from GET /api/v1/announcements (`announcements.view-all`).
 */
class AnnouncementsManage extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    public const STATUSES = ['draft' => 'Drafts', 'published' => 'Published', 'archived' => 'Archived'];

    #[Url(except: 'draft')]
    public string $status = 'draft';

    #[Url(except: 1)]
    public int $page = 1;

    public function showStatus(string $status): void
    {
        $this->status = array_key_exists($status, self::STATUSES) ? $status : 'draft';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $status = array_key_exists($this->status, self::STATUSES) ? $this->status : 'draft';
        $result = null;
        try {
            $result = $this->api()->get('/announcements', ['status' => $status, 'per_page' => 25, 'page' => max(1, $this->page)]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading announcements.', "You don't have access to manage announcements.");
        }

        return view('livewire.portal.announcements-manage', [
            'items' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'current' => $status,
            'mayAdd' => $this->may('announcements.create'),
        ]);
    }
}
