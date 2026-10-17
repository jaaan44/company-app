<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * People → Staff records (Phase 36A): every staff record, searchable by
 * name or employee number and filtered by status, 25 per page, through
 * GET /api/v1/staff. "New staff record" shows with `staff.create`.
 */
class StaffList extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = '';

    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    /** Phase 37B (DEC-068): back to the list's starting filters. */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $result = null;
        try {
            $result = $this->api()->get('/staff', [
                'q' => trim($this->search),
                'status' => in_array($this->status, ['active', 'inactive', 'separated'], true) ? $this->status : null,
                'page' => $this->page,
                'per_page' => 25,
            ]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading staff records.', "You don't have access to staff records.");
        }

        return view('livewire.admin.staff-list', [
            'staff' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'canCreate' => $this->may('staff.create'),
        ]);
    }
}
