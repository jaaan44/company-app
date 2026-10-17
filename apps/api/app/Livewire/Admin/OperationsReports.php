<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operations → Service reports / Incidents (Phase 36C): every report the
 * API lets this person see, filtered by status (by default the ones
 * waiting: submitted service reports; reported or under-investigation
 * incidents), severity for incidents, and dates, 25 per page.
 */
class OperationsReports extends Component
{
    use CallsInternalApi;

    public const STATUSES = [
        'service' => ['submitted' => 'Submitted', 'reviewed' => 'Reviewed', 'rejected' => 'Rejected', 'draft' => 'Draft'],
        'incidents' => ['open' => 'Open', 'reported' => 'Reported', 'under_investigation' => 'Under investigation', 'resolved' => 'Resolved', 'closed' => 'Closed'],
    ];

    public const SEVERITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];

    #[Locked]
    public string $kind = 'service';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $severity = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public int $page = 1;

    public function mount(string $kind = 'service'): void
    {
        $this->kind = $kind === 'incidents' ? 'incidents' : 'service';
        if ($this->status === '') {
            $this->status = $this->kind === 'service' ? 'submitted' : 'open';
        }
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    /** Phase 37B (DEC-068): back to the list's starting filters. */
    public function clearFilters(): void
    {
        $this->reset('status', 'severity', 'from', 'to', 'page');
        $this->status = $this->kind === 'service' ? 'submitted' : 'open';
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function fetch(?string $status): array
    {
        $date = fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;

        return $this->api()->get($this->kind === 'service' ? '/service-reports' : '/incident-reports', [
            'status' => $status,
            'severity' => $this->kind === 'incidents' && array_key_exists($this->severity, self::SEVERITIES) ? $this->severity : null,
            'from' => $date($this->from),
            'to' => $date($this->to),
            'per_page' => 25,
            'page' => $this->page,
        ]);
    }

    public function render(): View
    {
        $result = null;
        $status = array_key_exists($this->status, self::STATUSES[$this->kind]) ? $this->status : null;
        try {
            if ($status === 'open') {
                // The API filters one status at a time; "open" is both.
                $a = $this->fetch('reported');
                $b = $this->fetch('under_investigation');
                $rows = array_merge($a['data'], $b['data']);
                usort($rows, fn ($x, $y) => strcmp($y['occurred_at'], $x['occurred_at']));
                $result = ['data' => $rows, 'meta' => [
                    'total' => ($a['meta']['total'] ?? 0) + ($b['meta']['total'] ?? 0),
                    'current_page' => $this->page,
                    'last_page' => max($a['meta']['last_page'] ?? 1, $b['meta']['last_page'] ?? 1),
                ]];
            } else {
                $result = $this->fetch($status);
            }
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the reports.', "You don't have access to these reports.");
        }

        return view('livewire.admin.operations-reports', [
            'reports' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'timezone' => CompanyTimezone::value(),
        ]);
    }
}
