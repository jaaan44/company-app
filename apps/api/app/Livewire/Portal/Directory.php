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
 *
 * Phase 37E (DEC-072), after the reference's "All Employees": each person
 * is a card, and the list filters by status (the operational statuses and
 * On leave; only for `staff-status.view` holders, as the API requires) and
 * by department (from `GET /departments`; hidden if they can't load). The
 * filters live in the URL too.
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

    /** Phase 37E: '' (everyone), an operational status, or 'on_leave'. */
    #[Url(as: 'show', except: '')]
    public string $show = '';

    #[Url(except: '')]
    public string $department = '';

    /** The status chips, in the order they show. */
    public const FILTERS = [
        '' => 'All', 'available' => 'Available', 'in_meeting' => 'In a meeting', 'busy' => 'Busy',
        'in_field' => 'In the field', 'off_duty' => 'Off duty', 'on_leave' => 'On leave',
    ];

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedDepartment(): void
    {
        $this->page = 1;
    }

    public function showOnly(string $filter): void
    {
        $this->show = array_key_exists($filter, self::FILTERS) ? $filter : '';
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
        $mayViewStatus = Auth::user()?->can('staff-status.view') ?? false;
        $show = $mayViewStatus && array_key_exists($this->show, self::FILTERS) ? $this->show : '';

        try {
            $result = $this->api()->get('/staff', array_filter([
                'status' => 'active',
                'per_page' => self::PAGE_SIZE,
                'page' => max(1, $this->page),
                'q' => $search,
                'department' => $this->department,
                'operational_status' => $show !== '' && $show !== 'on_leave' ? $show : null,
                'on_leave' => $show === 'on_leave' ? 1 : null,
            ], fn ($value) => $value !== null && $value !== ''));
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
            'currentShow' => $show,
            'departments' => $result === null ? [] : $this->departments(), // not when the list itself failed
            // Phase 37D: each person's status, for those who may see it (staff-status.view).
            'mayViewStatus' => $mayViewStatus,
        ]);
    }

    /**
     * The active departments for the filter, A–Z by the API's order, or
     * none when they can't be listed (no `organization.view`, or an error).
     *
     * @return list<array{public_id: string, name: string}>
     */
    private function departments(): array
    {
        if (! (Auth::user()?->can('organization.view') ?? false)) {
            return [];
        }

        try {
            $departments = $this->api()->get('/departments', ['status' => 'active', 'per_page' => 100])['data'] ?? [];
        } catch (PortalApiException) {
            return [];
        }

        return array_values(array_map(fn (array $d) => ['public_id' => (string) $d['public_id'], 'name' => (string) $d['name']], $departments));
    }
}
