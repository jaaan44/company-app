<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * HR → Leave requests (Phase 36B): every request the API lets this person
 * see (an Administrator sees all), pending first by default, filtered by
 * status, type and dates, 25 per page. "Request leave for someone" shows
 * with `leave-requests.manage`.
 */
class LeaveRequests extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    #[Url(except: 'pending')]
    public string $status = 'pending';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public int $page = 1;

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    /** Phase 37B (DEC-068): back to the list's starting filters. */
    public function clearFilters(): void
    {
        $this->reset('status', 'type', 'from', 'to', 'page');
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $result = null;
        $types = [];
        $date = fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        try {
            $types = $this->api()->get('/leave-types', ['per_page' => 100])['data'] ?? [];
            $result = $this->api()->get('/leave-requests', [
                'status' => array_key_exists($this->status, self::STATUSES) ? $this->status : null,
                'type' => preg_match('/^[0-9A-Za-z]{1,64}$/', $this->type) ? $this->type : null,
                'from' => $date($this->from),
                'to' => $date($this->to),
                'per_page' => 25,
                'page' => $this->page,
            ]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading leave requests.', "You don't have access to leave requests.");
        }

        return view('livewire.admin.leave-requests', [
            'requests' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'types' => $types,
            'canCreate' => $this->may('leave-requests.manage'),
        ]);
    }
}
