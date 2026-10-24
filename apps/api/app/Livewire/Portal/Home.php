<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Portal Home (Phase 34A): the mobile Home (Phase 27) from `GET /me/home`
 * — greeting, Today, Needs attention and Latest announcements. The API
 * data is fetched on every render and never kept in a public property,
 * so it is not echoed into the page's Livewire snapshot.
 *
 * Phase 37D adds quick actions (links to existing pages, shown by the same
 * permissions as their pages' own buttons) and My team: up to six active
 * colleagues in the person's team, from `GET /staff`, with their status.
 * My team is a convenience: if it can't load, Home shows without it.
 */
class Home extends Component
{
    use CallsInternalApi;

    /** How many colleagues My team shows. */
    public const TEAM_SIZE = 6;

    public function render(): View
    {
        $this->errorMessage = null;
        $home = null;

        try {
            $home = $this->api()->get('/me/home')['data'] ?? null;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your Home.');
        }

        $user = Auth::user();

        return view('livewire.portal.home', [
            'home' => $home,
            'team' => $this->team($home),
            'mayAddTasks' => $user?->can('tasks.create') ?? false,
            'mayViewStatus' => $user?->can('staff-status.view') ?? false,
        ]);
    }

    /**
     * My team's colleagues (Phase 37D): active staff in the person's team,
     * without the person themselves, or null when there's no team, no
     * permission to see the directory, or the request fails.
     *
     * @param  array<string, mixed>|null  $home
     * @return list<array<string, mixed>>|null
     */
    private function team(?array $home): ?array
    {
        $teamId = $home['staff']['team']['public_id'] ?? null;
        if ($teamId === null || ! (Auth::user()?->can('staff.view') ?? false)) {
            return null;
        }

        try {
            $staff = $this->api()->get('/staff', ['status' => 'active', 'team' => $teamId, 'per_page' => self::TEAM_SIZE + 1])['data'] ?? [];
        } catch (PortalApiException) {
            return null;
        }

        return collect($staff)
            ->reject(fn (array $person) => $person['public_id'] === $home['staff']['public_id'])
            ->take(self::TEAM_SIZE)
            ->values()
            ->all();
    }
}
