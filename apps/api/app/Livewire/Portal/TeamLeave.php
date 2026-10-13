<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Team requests (Phase 34C): the mobile team list (Phase 31,
 * R-3) — my direct reports' requests from `GET /me/team-leave-requests`,
 * Pending (soonest first) / Approved / Rejected / All, 20 per page. A
 * person who reviews nobody sees "No requests are waiting for you."
 */
class TeamLeave extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 20;

    public const FILTERS = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'];

    #[Url(except: 'pending')]
    public string $status = 'pending';

    #[Url(except: 1)]
    public int $page = 1;

    public function show(string $status): void
    {
        $this->status = array_key_exists($status, self::FILTERS) ? $status : 'pending';
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
        $status = array_key_exists($this->status, self::FILTERS) ? $this->status : 'pending';
        $result = null;

        try {
            $result = $this->api()->get('/me/team-leave-requests', ['status' => $status, 'per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page)]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading team requests.');
        }

        $meta = $result['meta'] ?? null;

        return view('livewire.portal.team-leave', [
            'requests' => $result['data'] ?? [],
            'meta' => $meta,
            'currentStatus' => $status,
            'hasTeam' => ($meta['can_review'] ?? false) === true && ($meta['direct_reports_count'] ?? 0) > 0,
            'pendingCount' => $meta['pending_count'] ?? 0,
        ]);
    }
}
