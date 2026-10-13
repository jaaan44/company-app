<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Leave (Phase 34C): the mobile Leave screen (Phase 31) —
 * balances from `GET /me/leave-balances`, my requests from
 * `GET /me/leave-requests` (20 per page, newest first), and a Team
 * requests entry only for a reviewer who manages someone
 * (`GET /me/team-leave-requests` meta, R-1).
 */
class Leave extends Component
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
        $requests = null;
        $balances = [];
        $team = null;

        try {
            $requests = $this->api()->get('/me/leave-requests', ['per_page' => self::PAGE_SIZE, 'page' => max(1, $this->page)]);
            $balances = $this->api()->get('/me/leave-balances')['data'] ?? [];
            $team = $this->api()->get('/me/team-leave-requests', ['status' => 'pending', 'per_page' => 1])['meta'] ?? null;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your leave.');
        }

        return view('livewire.portal.leave', [
            'requests' => $requests['data'] ?? [],
            'meta' => $requests['meta'] ?? null,
            'balances' => $balances,
            'hasTeam' => ($team['can_review'] ?? false) === true && ($team['direct_reports_count'] ?? 0) > 0,
            'pendingCount' => $team['pending_count'] ?? 0,
        ]);
    }
}
