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
 * permissions as their pages' own buttons). Phase 37E (DEC-072) replaces
 * 37D's My team with Employees: the first four active staff company-wide,
 * in the directory's order (A–Z), as cards, from `GET /staff`, with the
 * total for "View all". Employees is a convenience: if it can't load, Home
 * shows without it.
 */
class Home extends Component
{
    use CallsInternalApi;

    /** How many employees Home shows. */
    public const EMPLOYEES_SHOWN = 4;

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
            'employees' => $home === null ? null : $this->employees(),
            'mayAddTasks' => $user?->can('tasks.create') ?? false,
            'mayViewStatus' => $user?->can('staff-status.view') ?? false,
        ]);
    }

    /**
     * Home's Employees (Phase 37E): the first four active staff, A–Z, and
     * how many there are in all; null without `staff.view` or when the
     * request fails.
     *
     * @return array{people: list<array<string, mixed>>, total: int}|null
     */
    private function employees(): ?array
    {
        if (! (Auth::user()?->can('staff.view') ?? false)) {
            return null;
        }

        try {
            $result = $this->api()->get('/staff', ['status' => 'active', 'per_page' => self::EMPLOYEES_SHOWN]);
        } catch (PortalApiException) {
            return null;
        }

        return ['people' => array_values($result['data'] ?? []), 'total' => (int) ($result['meta']['total'] ?? 0)];
    }
}
